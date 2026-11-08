<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

use Throwable;
use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Support\Archive;
use Youvision\ThinkBackup\Support\Identifier;
use Youvision\ThinkBackup\Support\LegacyPhpReader;
use Youvision\ThinkBackup\Support\Path;
use Youvision\ThinkBackup\Support\SqlSplitter;

/**
 * 数据库恢复器。
 *
 * 支持本项目生成的 backup.json + .sql 备份，也支持旧版帝国备份王的
 * config.php + table_1.php 备份。恢复时按清单顺序执行 SQL，最后统一
 * 打开外键检查。
 */
final class Restorer
{
    protected Database $database;

    /**
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(Database $database, array $options = [])
    {
        $this->database = $database;
        $this->options = array_replace(self::defaults(), $options);
    }

    /**
     * 执行恢复。
     *
     * @param callable(array<string, mixed>): void|null $progress
     * @return array<string, mixed>
     */
    public function run(string $root, string $name, ?callable $progress = null): array
    {
        $started = microtime(true);
        $source = $this->resolve($root, $name);
        $temporary = null;

        try {
            if ($source['zip']) {
                $temporary = Path::join($root, '.restore_' . Path::randomSuffix(10));
                Archive::extract($source['path'], $temporary);
                $directory = $temporary;
            } else {
                $directory = $source['path'];
            }

            $manifest = Manifest::load($directory);
            $databaseName = (string) ($this->options['database'] ?? '');

            if ($databaseName === '') {
                $databaseName = (string) ($manifest['database'] ?? '');
            }

            if ($databaseName === '') {
                $databaseName = $this->database->database();
            }

            $databaseName = Identifier::assertDatabase($databaseName);

            if ((bool) $this->options['create_database']) {
                $charset = (string) ($manifest['charset'] ?? '');
                $charset = preg_match('/^[A-Za-z0-9_]+$/', $charset) ? $charset : 'utf8mb4';
                $this->database->execute(
                    'CREATE DATABASE IF NOT EXISTS ' . Identifier::quote($databaseName)
                    . ' DEFAULT CHARACTER SET ' . $charset
                );
            }

            $this->database->switchDatabase($databaseName);

            $selected = Identifier::list($this->options['tables'] ?? []);
            $tables = [];

            foreach ($manifest['tables'] as $table) {
                if ($selected !== [] && !in_array($table['name'], $selected, true)) {
                    continue;
                }

                $tables[] = $table;
            }

            if ($tables === []) {
                throw new BackupException('备份中没有匹配的表：' . implode(',', $selected));
            }

            $this->database->execute('SET FOREIGN_KEY_CHECKS=0');
            $statements = 0;
            $counts = [];

            foreach ($tables as $table) {
                $tableName = Identifier::assertTable((string) $table['name']);
                $files = (array) ($table['files'] ?? []);

                if ($files === []) {
                    $files = Manifest::tableFiles($directory, $tableName);
                }

                if ($files === []) {
                    throw new BackupException('表 ' . $tableName . ' 没有可恢复的数据文件。');
                }

                $tableStatements = 0;

                foreach ($files as $file) {
                    $file = (string) $file;

                    if (
                        $file === ''
                        || str_contains($file, '..')
                        || str_contains($file, '/')
                        || str_contains($file, '\\')
                    ) {
                        throw new BackupException('备份清单包含不安全的文件：' . $file);
                    }

                    $path = Path::join($directory, $file);

                    if (!Path::within($directory, $path)) {
                        throw new BackupException('备份文件超出备份目录：' . $file);
                    }

                    if (!is_file($path)) {
                        throw new BackupException('备份文件不存在：' . $file);
                    }

                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                    if ($extension === 'php') {
                        $reader = new LegacyPhpReader();
                        $count = 0;

                        foreach ($reader->readFile($path) as $sql) {
                            $this->database->execute($sql);
                            $count++;
                        }
                    } elseif ($extension === 'sql') {
                        $splitter = new SqlSplitter();
                        $count = $splitter->splitFile($path, function (string $sql): void {
                            $this->database->execute($sql);
                        });
                    } else {
                        throw new BackupException('不支持的备份文件类型：' . $file);
                    }

                    $tableStatements += $count;
                    $statements += $count;

                    if ($progress !== null) {
                        $progress([
                            'type' => 'file',
                            'table' => $tableName,
                            'file' => $file,
                            'statements' => $count,
                        ]);
                    }
                }

                $counts[] = ['name' => $tableName, 'files' => count($files), 'statements' => $tableStatements];
            }
        } finally {
            try {
                $this->database->execute('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $e) {
            }

            if ($temporary !== null) {
                Path::remove($temporary);
            }
        }

        return [
            'name' => (string) ($manifest['name'] ?? basename($directory)),
            'database' => $databaseName,
            'tables' => $counts,
            'statements' => $statements,
            'elapsed' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'database' => '',
            'tables' => [],
            'create_database' => false,
        ];
    }

    /**
     * 定位备份目录或压缩包，并保证路径位于备份根目录内。
     *
     * @return array{path: string, zip: bool}
     */
    protected function resolve(string $root, string $name): array
    {
        $root = Path::absolute($root);
        $name = trim($name);

        if ($name === '') {
            throw new BackupException('请指定要恢复的备份名称或路径。');
        }

        $candidates = Path::isAbsolute($name)
            ? [$name, $name . '.zip']
            : [Path::join($root, $name), Path::join($root, $name . '.zip')];

        foreach ($candidates as $candidate) {
            $candidate = Path::normalize($candidate);

            if (!Path::within($root, $candidate)) {
                throw new BackupException('备份路径必须位于备份根目录内：' . $name);
            }

            if (is_dir($candidate)) {
                return ['path' => $candidate, 'zip' => false];
            }

            if (Archive::isZip($candidate)) {
                return ['path' => $candidate, 'zip' => true];
            }
        }

        throw new BackupException('备份不存在：' . $name);
    }
}