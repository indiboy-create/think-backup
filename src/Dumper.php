<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

use Throwable;
use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Support\Archive;
use Youvision\ThinkBackup\Support\ChunkWriter;
use Youvision\ThinkBackup\Support\Identifier;
use Youvision\ThinkBackup\Support\Path;
use Youvision\ThinkBackup\Support\Schema;

/**
 * 数据库导出器。
 *
 * 负责把指定数据库中的表结构和数据写成可分卷的 SQL 文件，并生成
 * backup.json、readme.txt，可选生成 zip 压缩包。
 */
final class Dumper
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
     * 执行备份。
     *
     * @param callable(array<string, mixed>): void|null $progress
     * @return array<string, mixed>
     */
    public function run(string $root, ?callable $progress = null): array
    {
        $started = microtime(true);
        $root = Path::absolute($root);
        Path::ensureDirectory($root, (int) $this->options['permissions']);

        if (!(bool) $this->options['structure'] && !(bool) $this->options['data']) {
            throw new BackupException('structure 与 data 不能同时为 false，否则备份没有内容。');
        }

        $databaseName = (string) ($this->options['database'] ?? '');
        $databaseName = $databaseName !== '' ? Identifier::assertDatabase($databaseName) : $this->database->database();
        $this->database->switchDatabase($databaseName);

        $tables = $this->selectedTables();

        if ($tables === []) {
            throw new BackupException('没有匹配的备份表，请检查 prefix、tables 与 exclude 配置。');
        }

        $name = $this->backupName($databaseName);
        $directory = Path::join($root, $name);

        if (file_exists($directory)) {
            throw new BackupException('备份目录已存在，请更换名称：' . $directory);
        }

        Path::ensureDirectory($directory, (int) $this->options['permissions']);

        $charset = (string) $this->options['charset'];
        $charset = $charset === '' || $charset === 'auto' ? $this->databaseCharset($databaseName) : $this->safeCharset($charset);
        $this->options['charset'] = $charset;

        $status = (bool) $this->options['count_exact'] ? [] : Schema::status($this->database);
        $manifestTables = [];
        $totalRows = 0;
        $totalFiles = 0;
        $autoIncrement = Identifier::autoIncrementMap($this->options['ignore_auto_increment']);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $isView = $table['type'] === 'VIEW';
            $columns = $isView ? [] : Schema::columns($this->database, $tableName);
            $types = [];

            foreach ($columns as $column) {
                $types[$column['name']] = $column['type'];
            }

            $rows = $isView ? 0 : Schema::count($this->database, $tableName, (bool) $this->options['count_exact'], $status);
            $writer = new ChunkWriter($directory, $tableName, [
                'mode' => $this->options['mode'],
                'size' => $this->options['size'],
                'line' => $this->options['line'],
                'charset' => $charset,
            ]);

            if ((bool) $this->options['structure']) {
                $create = Schema::createStatement($this->database, $tableName, $table['type']);

                if ($create !== '') {
                    $drop = $isView ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ';
                    $writer->write($drop . Identifier::quote($tableName) . ';');
                    $writer->write($create . ';');
                }
            }

            $exported = 0;

            if (!$isView && (bool) $this->options['data']) {
                $exported = $this->dumpRows(
                    $writer,
                    $tableName,
                    $columns,
                    $types,
                    $autoIncrement[$tableName] ?? []
                );
            }

            $files = $writer->close();
            $totalRows += $exported;
            $totalFiles += count($files);
            $manifestTables[] = [
                'name' => $tableName,
                'rows' => $exported,
                'files' => $files,
            ];

            if ($progress !== null) {
                $progress([
                    'type' => 'table',
                    'table' => $tableName,
                    'rows' => $exported,
                    'expected_rows' => $rows,
                    'files' => count($files),
                    'view' => $isView,
                ]);
            }
        }

        $manifest = Manifest::make([
            'name' => $name,
            'database' => $databaseName,
            'connection' => (string) ($this->options['connection'] ?? ''),
            'server_version' => $this->serverVersion(),
            'charset' => $charset,
            'format' => 'sql',
            'mode' => $this->options['mode'],
            'size' => $this->options['size'],
            'line' => $this->options['line'],
            'insert' => $this->options['insert'],
            'complete_insert' => $this->options['complete_insert'],
            'hex' => $this->options['hex'],
            'structure' => $this->options['structure'],
            'data' => $this->options['data'],
            'created_at' => date('Y-m-d H:i:s'),
            'tables' => $manifestTables,
        ]);

        Manifest::save($directory, $manifest);
        $this->writeReadme($directory, $manifest, $totalRows, $totalFiles);

        $zip = null;

        if ((bool) $this->options['zip']) {
            $zip = Path::join($root, $name . '.zip');
            Archive::pack($directory, $zip, (int) $this->options['zip_level']);

            if (!(bool) $this->options['zip_keep_directory']) {
                Path::remove($directory);
            }
        }

        return [
            'name' => $name,
            'path' => $directory,
            'zip' => $zip,
            'database' => $databaseName,
            'charset' => $charset,
            'tables' => $manifestTables,
            'rows' => $totalRows,
            'files' => $totalFiles,
            'elapsed' => round(microtime(true) - $started, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'connection' => '',
            'database' => '',
            'name' => '',
            'tables' => [],
            'exclude' => [],
            'prefix' => '',
            'structure' => true,
            'data' => true,
            'mode' => 'size',
            'size' => 2048,
            'line' => 1000,
            'batch' => 1000,
            'insert' => 'insert',
            'complete_insert' => true,
            'hex' => 'blob',
            'ignore_auto_increment' => [],
            'charset' => 'auto',
            'zip' => false,
            'zip_level' => 6,
            'zip_keep_directory' => true,
            'count_exact' => false,
            'include_views' => false,
            'permissions' => 0755,
        ];
    }

    /**
     * @return array<int, array{name: string, type: string}>
     */
    protected function selectedTables(): array
    {
        $tables = Schema::tables($this->database, (bool) $this->options['include_views']);
        $include = Identifier::list($this->options['tables'] ?? []);
        $exclude = Identifier::list($this->options['exclude'] ?? []);
        $prefix = (string) ($this->options['prefix'] ?? '');
        $selected = [];

        foreach ($tables as $table) {
            if ($include !== [] && !in_array($table['name'], $include, true)) {
                continue;
            }

            if ($include === [] && $prefix !== '' && !str_starts_with($table['name'], $prefix)) {
                continue;
            }

            if (in_array($table['name'], $exclude, true)) {
                continue;
            }

            $selected[] = $table;
        }

        return $selected;
    }

    /**
     * 逐批导出表数据。
     *
     * @param array<int, array{name: string, type: string, key: string, extra: string}> $columns
     * @param array<string, string> $types
     * @param array<int, string> $ignoreAutoIncrement
     */
    protected function dumpRows(ChunkWriter $writer, string $table, array $columns, array $types, array $ignoreAutoIncrement): int
    {
        $batch = max(1, (int) $this->options['batch']);

        if ((string) $this->options['mode'] === 'line') {
            $line = (int) $this->options['line'];

            if ($line > 0) {
                $batch = min($batch, $line);
            }
        }


        $primary = Schema::primaryColumn($columns);
        $columns = array_values(array_filter($columns, static function (array $column): bool {
            return stripos($column['extra'], 'GENERATED') === false;
        }));
        $names = array_map(static fn(array $column): string => $column['name'], $columns);
        $offset = 0;
        $exported = 0;

        while (true) {
            $sql = 'SELECT * FROM ' . Identifier::quote($table);

            if ($primary !== null) {
                $sql .= ' ORDER BY ' . Identifier::quote($primary) . ' ASC';
            }

            $sql .= ' LIMIT ' . $offset . ', ' . $batch;
            $rows = $this->database->query($sql);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $writer->write($this->insertStatement($table, $names, $types, $row, $ignoreAutoIncrement), true);
                $exported++;
            }

            if (count($rows) < $batch) {
                break;
            }

            $offset += $batch;
        }

        return $exported;
    }

    /**
     * 生成单条 INSERT / REPLACE 语句。
     *
     * @param array<int, string> $columns
     * @param array<string, string> $types
     * @param array<string, mixed> $row
     * @param array<int, string> $ignoreAutoIncrement
     */
    protected function insertStatement(string $table, array $columns, array $types, array $row, array $ignoreAutoIncrement): string
    {
        $insert = (string) $this->options['insert'] === 'replace' ? 'REPLACE INTO ' : 'INSERT INTO ';
        $sql = $insert . Identifier::quote($table);

        if ((bool) $this->options['complete_insert']) {
            $quoted = array_map(static fn(string $column): string => Identifier::quote($column), $columns);
            $sql .= ' (' . implode(',', $quoted) . ')';
        }

        $values = [];

        foreach ($columns as $column) {
            $value = $row[$column] ?? null;

            if ($value === null || in_array($column, $ignoreAutoIncrement, true)) {
                $values[] = 'NULL';
                continue;
            }

            $type = $types[$column] ?? '';
            $hex = (string) $this->options['hex'];

            if ($hex === 'all') {
                $values[] = '0x' . bin2hex((string) $value);
                continue;
            }

            if ($hex === 'blob' && $this->isBinaryType($type)) {
                $values[] = '0x' . bin2hex((string) $value);
                continue;
            }

            if ($this->isNumericType($type) && $this->isNumericValue((string) $value)) {
                $values[] = (string) $value === '' ? '0' : (string) $value;
                continue;
            }

            $values[] = "'" . Identifier::literal((string) $value) . "'";
        }

        return $sql . ' VALUES (' . implode(',', $values) . ');';
    }

    /**
     * 生成备份目录名。
     */
    protected function backupName(string $database): string
    {
        $name = trim((string) ($this->options['name'] ?? ''));

        if ($name === '') {
            $name = $database . '_' . date('YmdHis') . '_' . Path::randomSuffix(6);
        }

        $name = preg_replace('/[^\p{L}\p{N}_.-]+/u', '_', $name) ?? 'backup';
        $name = trim($name, '.');
        $name = str_replace('..', '_', $name);

        if ($name === '') {
            throw new BackupException('备份名称不合法。');
        }

        return $name;
    }

    protected function databaseCharset(string $database): string
    {
        try {
            $rows = $this->database->query(
                "SELECT DEFAULT_CHARACTER_SET_NAME AS `charset` FROM information_schema.SCHEMATA"
                . " WHERE SCHEMA_NAME = '" . Identifier::literal($database) . "'"
            );
            $charset = (string) ($rows[0]['charset'] ?? '');

            if (preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
                return $charset;
            }
        } catch (Throwable $e) {
        }

        $configured = $this->database->config('charset');

        if (is_string($configured) && preg_match('/^[A-Za-z0-9_]+$/', $configured)) {
            return $configured;
        }

        return 'utf8mb4';
    }

    protected function safeCharset(string $charset): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $charset) ? $charset : 'utf8mb4';
    }

    protected function isBinaryType(string $type): bool
    {
        return (bool) preg_match('/blob|binary|varbinary|bit|geometry|point|linestring|polygon/i', $type);
    }

    protected function isNumericType(string $type): bool
    {
        return (bool) preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)/i', $type);
    }

    protected function isNumericValue(string $value): bool
    {
        return $value === '' || is_numeric($value);
    }

    protected function serverVersion(): string
    {
        try {
            return $this->database->version();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    protected function writeReadme(string $directory, array $manifest, int $totalRows, int $totalFiles): void
    {
        $mode = $manifest['mode'] === 'line'
            ? '按记录分卷，每卷最多 ' . $manifest['line'] . ' 条记录'
            : '按文件大小分卷，每卷约 ' . $manifest['size'] . ' KB';

        $content = "think-backup 数据库备份\n"
            . "========================\n\n"
            . '数据库：' . $manifest['database'] . "\n"
            . '备份时间：' . $manifest['created_at'] . "\n"
            . '分卷方式：' . $mode . "\n"
            . '字符集：' . $manifest['charset'] . "\n"
            . '数据库版本：' . ($manifest['server_version'] !== '' ? $manifest['server_version'] : '未知') . "\n"
            . '表数量：' . count($manifest['tables']) . "\n"
            . '记录总数：' . $totalRows . "\n"
            . '文件总数：' . $totalFiles . "\n\n"
            . "恢复方式：\n"
            . "Backup::restore('" . $manifest['name'] . "');\n\n"
            . "说明：\n"
            . "- backup.json 保存备份清单，请勿手动修改。\n"
            . "- 每个 .sql 文件都可以单独执行，文件头已包含字符集与外键设置。\n"
            . "- 本备份只包含基础表结构（可选视图）与数据，不包含触发器、存储过程和事件。\n";

        if (@file_put_contents(Path::join($directory, 'readme.txt'), $content) === false) {
            throw new BackupException('无法写入备份说明文件。');
        }
    }
}