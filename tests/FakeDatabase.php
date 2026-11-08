<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Tests;

use Youvision\ThinkBackup\Database;

/**
 * 内存数据库，用于验证备份文件生成、恢复 SQL 与元数据查询。
 */
final class FakeDatabase implements Database
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $tables;

    /**
     * @var array<int, string>
     */
    public array $databases = ['demo', 'information_schema', 'mysql'];

    /**
     * @var array<int, string>
     */
    public array $queries = [];

    /**
     * @var array<int, string>
     */
    public array $executed = [];

    protected string $databaseName;

    protected string $charset;

    /**
     * @param array<string, array<string, mixed>> $tables
     */
    public function __construct(array $tables = [], string $database = 'demo', string $charset = 'utf8mb4')
    {
        $this->tables = $tables;
        $this->databaseName = $database;
        $this->charset = $charset;
    }

    public function query(string $sql): array
    {
        $this->queries[] = $sql;
        $sql = trim($sql);

        if (stripos($sql, 'SHOW DATABASES') === 0) {
            return array_map(static fn(string $database): array => ['Database' => $database], $this->databases);
        }

        if (stripos($sql, 'SHOW FULL TABLES') === 0) {
            $rows = [];

            foreach ($this->tables as $name => $table) {
                $type = (string) ($table['type'] ?? 'BASE TABLE');

                if (stripos($sql, "Table_type = 'BASE TABLE'") !== false && $type !== 'BASE TABLE') {
                    continue;
                }

                $rows[] = [
                    'Tables_in_' . $this->databaseName => $name,
                    'Table_type' => $type,
                ];
            }

            return $rows;
        }

        if (stripos($sql, 'SHOW TABLE STATUS') === 0) {
            $rows = [];

            foreach ($this->tables as $name => $table) {
                $rows[] = ['Name' => $name, 'Rows' => count((array) ($table['rows'] ?? []))];
            }

            return $rows;
        }

        if (preg_match('/^SHOW FULL COLUMNS FROM `(.+)`$/i', $sql, $match)) {
            $rows = [];

            foreach ((array) ($this->tables[$match[1]]['columns'] ?? []) as $column) {
                $rows[] = [
                    'Field' => $column['name'],
                    'Type' => $column['type'],
                    'Null' => 'YES',
                    'Key' => $column['key'] ?? '',
                    'Default' => null,
                    'Extra' => $column['extra'] ?? '',
                    'Privileges' => 'select,insert,update,references',
                    'Comment' => '',
                ];
            }

            return $rows;
        }

        if (preg_match('/^SHOW CREATE (TABLE|VIEW) `(.+)`$/i', $sql, $match)) {
            $type = strtoupper($match[1]);
            $table = $match[2];
            $create = $type === 'VIEW'
                ? 'CREATE ALGORITHM=UNDEFINED VIEW `' . $table . '` AS SELECT 1 AS `id`'
                : 'CREATE TABLE `' . $table . '` (`id` int(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

            return [$type === 'VIEW' ? ['View' => $table, 'Create View' => $create] : ['Table' => $table, 'Create Table' => $create]];
        }

        if (preg_match('/^SELECT COUNT\(\*\) AS `total` FROM `(.+)`$/i', $sql, $match)) {
            return [['total' => count((array) ($this->tables[$match[1]]['rows'] ?? []))]];
        }

        if (preg_match('/^SELECT \* FROM `(.+)`(?: ORDER BY `[^`]+` ASC)? LIMIT (\d+), (\d+)$/i', $sql, $match)) {
            $rows = array_values((array) ($this->tables[$match[1]]['rows'] ?? []));

            return array_slice($rows, (int) $match[2], (int) $match[3]);
        }

        if (stripos($sql, 'SELECT VERSION()') === 0) {
            return [['version' => '8.0.36']];
        }

        if (stripos($sql, 'SELECT DATABASE()') === 0) {
            return [['db' => $this->databaseName]];
        }

        if (stripos($sql, 'DEFAULT_CHARACTER_SET_NAME') !== false) {
            return [['charset' => $this->charset]];
        }

        return [];
    }

    public function execute(string $sql): int
    {
        $this->executed[] = $sql;

        if (preg_match('/^USE `(.+)`$/i', trim($sql), $match)) {
            $this->databaseName = $match[1];

            return 0;
        }

        return 1;
    }

    public function config(string $name, $default = null)
    {
        return $name === 'charset' ? $this->charset : $default;
    }

    public function database(): string
    {
        return $this->databaseName;
    }

    public function switchDatabase(string $database): void
    {
        $this->databaseName = $database;
        $this->executed[] = 'USE `' . $database . '`';
    }

    public function version(): string
    {
        return '8.0.36';
    }
}