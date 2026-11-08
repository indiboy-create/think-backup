<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

use JsonException;
use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Support\Identifier;
use Youvision\ThinkBackup\Support\Path;

/**
 * 备份清单读写。
 *
 * 新版本备份使用备份目录中的 backup.json 描述数据库、表、分卷文件、
 * 字符集与导出选项。恢复旧版帝国备份王目录时，会读取其 config.php，
 * 并自动扫描 table_1.php、table_2.php 等数据文件。
 */
final class Manifest
{
    public const FILE = 'backup.json';

    public const VERSION = '1.0';

    /**
     * 生成标准清单。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function make(array $data): array
    {
        $tables = [];

        foreach ((array) ($data['tables'] ?? []) as $table) {
            if (is_string($table)) {
                $table = ['name' => $table, 'files' => []];
            }

            $name = (string) ($table['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $tables[] = [
                'name' => $name,
                'rows' => (int) ($table['rows'] ?? 0),
                'files' => array_values((array) ($table['files'] ?? [])),
            ];
        }

        return [
            'version' => self::VERSION,
            'name' => (string) ($data['name'] ?? ''),
            'database' => (string) ($data['database'] ?? ''),
            'connection' => (string) ($data['connection'] ?? ''),
            'server_version' => (string) ($data['server_version'] ?? ''),
            'charset' => (string) ($data['charset'] ?? ''),
            'format' => (string) ($data['format'] ?? 'sql'),
            'mode' => (string) ($data['mode'] ?? 'size'),
            'size' => (int) ($data['size'] ?? 0),
            'line' => (int) ($data['line'] ?? 0),
            'insert' => (string) ($data['insert'] ?? 'insert'),
            'complete_insert' => (bool) ($data['complete_insert'] ?? true),
            'hex' => (string) ($data['hex'] ?? 'blob'),
            'structure' => (bool) ($data['structure'] ?? true),
            'data' => (bool) ($data['data'] ?? true),
            'created_at' => (string) ($data['created_at'] ?? date('Y-m-d H:i:s')),
            'tables' => $tables,
        ];
    }

    /**
     * 写入清单。先写临时文件再改名，避免中断产生半截 JSON。
     *
     * @param array<string, mixed> $manifest
     */
    public static function save(string $directory, array $manifest): void
    {
        $file = Path::join($directory, self::FILE);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new BackupException('备份清单编码失败：' . json_last_error_msg());
        }

        $temporary = $file . '.tmp';

        if (@file_put_contents($temporary, $json) === false) {
            throw new BackupException('无法写入备份清单：' . $temporary);
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            throw new BackupException('无法保存备份清单：' . $file);
        }
    }

    /**
     * 读取清单。优先读取 backup.json，不存在时兼容旧版 config.php。
     *
     * @return array<string, mixed>
     */
    public static function load(string $directory): array
    {
        $file = Path::join($directory, self::FILE);

        if (is_file($file)) {
            $content = @file_get_contents($file);

            if ($content === false) {
                throw new BackupException('无法读取备份清单：' . $file);
            }

            try {
                $manifest = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new BackupException('备份清单不是合法 JSON：' . $e->getMessage(), 0, $e);
            }

            if (!is_array($manifest) || !isset($manifest['tables']) || !is_array($manifest['tables'])) {
                throw new BackupException('备份清单缺少 tables 字段：' . $file);
            }

            return self::make($manifest);
        }

        $legacy = Path::join($directory, 'config.php');

        if (is_file($legacy)) {
            return self::fromLegacy($directory, $legacy);
        }

        return self::scan($directory);
    }

    /**
     * 扫描目录中的 SQL / PHP 数据文件，生成最小清单。
     *
     * @return array<string, mixed>
     */
    public static function scan(string $directory): array
    {
        $tables = [];
        $files = @scandir($directory);

        if ($files === false) {
            throw new BackupException('无法读取备份目录：' . $directory);
        }

        foreach ($files as $file) {
            if (!preg_match('/^(.+)_(\d+)\.(sql|php)$/i', $file, $match)) {
                continue;
            }

            $table = $match[1];

            if (!isset($tables[$table])) {
                $tables[$table] = ['name' => $table, 'rows' => 0, 'files' => []];
            }

            $tables[$table]['files'][] = $file;
        }

        $tables = array_values($tables);

        foreach ($tables as &$table) {
            $table['files'] = self::sortFiles($table['files']);
        }
        unset($table);

        if ($tables === []) {
            throw new BackupException('备份目录中没有可恢复的 .sql 或 .php 数据文件：' . $directory);
        }

        return self::make([
            'name' => basename($directory),
            'database' => '',
            'charset' => '',
            'format' => 'sql',
            'created_at' => date('Y-m-d H:i:s', (int) @filemtime($directory)),
            'tables' => $tables,
        ]);
    }

    /**
     * 从帝国备份王的 config.php 中解析清单。
     *
     * @return array<string, mixed>
     */
    public static function fromLegacy(string $directory, string $configFile): array
    {
        $content = @file_get_contents($configFile);

        if ($content === false) {
            throw new BackupException('无法读取旧版备份配置：' . $configFile);
        }

        $tableList = '';

        if (preg_match('/\$b_table\s*=\s*"([^"]*)"/', $content, $match)) {
            $tableList = $match[1];
        }

        $fileCounts = [];

        if (preg_match_all('/\$tb\[\'([^\']+)\'\]\s*=\s*(\d+)\s*;/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $item) {
                $fileCounts[$item[1]] = (int) $item[2];
            }
        }

        $read = static function (string $name) use ($content, $directory): string {
            if (preg_match('/\$' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/', $content, $match)) {
                return stripcslashes($match[1]);
            }

            return '';
        };

        $database = $read('b_dbname');
        $charset = $read('b_dbchar');
        $insert = $read('b_insertf') === 'insert' ? 'insert' : 'replace';
        $complete = preg_match('/\$b_beover\s*=\s*1\s*;/', $content) === 1;
        $hex = preg_match('/\$b_bakdatatype\s*=\s*1\s*;/', $content) === 1 ? 'all' : 'none';
        $mode = preg_match('/\$b_baktype\s*=\s*1\s*;/', $content) === 1 ? 'line' : 'size';
        $size = preg_match('/\$b_filesize\s*=\s*(\d+)\s*;/', $content, $match) ? (int) $match[1] : 0;
        $line = preg_match('/\$b_bakline\s*=\s*(\d+)\s*;/', $content, $match) ? (int) $match[1] : 0;
        $tables = [];

        foreach (Identifier::list(str_replace(',', ',', $tableList)) as $table) {
            $files = self::tableFiles($directory, $table);

            if ($files === [] && isset($fileCounts[$table])) {
                for ($index = 1; $index <= $fileCounts[$table]; $index++) {
                    $files[] = $table . '_' . $index . '.php';
                }
            }

            $tables[] = [
                'name' => $table,
                'rows' => 0,
                'files' => $files,
            ];
        }

        if ($tables === []) {
            $tables = self::scan($directory)['tables'];
        }

        return self::make([
            'name' => basename($directory),
            'database' => $database,
            'charset' => $charset,
            'format' => 'legacy',
            'mode' => $mode,
            'size' => $size,
            'line' => $line,
            'insert' => $insert,
            'complete_insert' => $complete,
            'hex' => $hex,
            'created_at' => date('Y-m-d H:i:s', (int) @filemtime($directory)),
            'tables' => $tables,
        ]);
    }

    /**
     * 返回某张表实际存在的分卷文件。
     *
     * @return array<int, string>
     */
    public static function tableFiles(string $directory, string $table): array
    {
        $files = @scandir($directory);

        if ($files === false) {
            return [];
        }

        $pattern = '/^' . preg_quote($table, '/') . '_(\d+)\.(sql|php)$/i';
        $matched = [];

        foreach ($files as $file) {
            if (preg_match($pattern, $file)) {
                $matched[] = $file;
            }
        }

        return self::sortFiles($matched);
    }

    /**
     * 按分卷序号排序。
     *
     * @param array<int, string> $files
     * @return array<int, string>
     */
    public static function sortFiles(array $files): array
    {
        usort($files, static function (string $left, string $right): int {
            preg_match('/_(\d+)\.(sql|php)$/i', $left, $leftMatch);
            preg_match('/_(\d+)\.(sql|php)$/i', $right, $rightMatch);

            return ((int) ($leftMatch[1] ?? 0)) <=> ((int) ($rightMatch[1] ?? 0));
        });

        return $files;
    }
}