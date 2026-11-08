<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use Youvision\ThinkBackup\Database;

/**
 * 数据库元数据查询。
 *
 * 统一封装 SHOW TABLES、SHOW TABLE STATUS、SHOW FULL COLUMNS 与
 * SHOW CREATE TABLE，避免备份与维护逻辑重复拼接元数据 SQL。
 */
final class Schema
{
    /**
     * 返回表与视图列表，基础表排在视图之前。
     *
     * @return array<int, array{name: string, type: string}>
     */
    public static function tables(Database $database, bool $includeViews = false): array
    {
        $sql = 'SHOW FULL TABLES';

        if (!$includeViews) {
            $sql .= " WHERE Table_type = 'BASE TABLE'";
        }

        $result = [];

        foreach ($database->query($sql) as $row) {
            $values = array_values($row);

            if ($values === []) {
                continue;
            }

            $result[] = [
                'name' => (string) $values[0],
                'type' => (string) ($values[1] ?? 'BASE TABLE'),
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $leftView = $left['type'] === 'VIEW';
            $rightView = $right['type'] === 'VIEW';

            if ($leftView === $rightView) {
                return strcmp($left['name'], $right['name']);
            }

            return $leftView ? 1 : -1;
        });

        return $result;
    }

    /**
     * 返回 SHOW TABLE STATUS 结果，以表名为键。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function status(Database $database): array
    {
        $status = [];

        foreach ($database->query('SHOW TABLE STATUS') as $row) {
            $name = (string) ($row['Name'] ?? '');

            if ($name !== '') {
                $status[$name] = $row;
            }
        }

        return $status;
    }

    /**
     * 统计表记录数。
     *
     * @param array<string, array<string, mixed>> $status
     */
    public static function count(Database $database, string $table, bool $exact = false, array $status = []): int
    {
        if ($exact || !isset($status[$table]['Rows'])) {
            $rows = $database->query('SELECT COUNT(*) AS `total` FROM ' . Identifier::quote($table));

            return (int) ($rows[0]['total'] ?? 0);
        }

        return (int) $status[$table]['Rows'];
    }

    /**
     * 返回字段信息，保持数据库中的字段顺序。
     *
     * @return array<int, array{name: string, type: string, key: string, extra: string}>
     */
    public static function columns(Database $database, string $table): array
    {
        $columns = [];

        foreach ($database->query('SHOW FULL COLUMNS FROM ' . Identifier::quote($table)) as $row) {
            $name = (string) ($row['Field'] ?? '');

            if ($name === '') {
                continue;
            }

            $columns[] = [
                'name' => $name,
                'type' => (string) ($row['Type'] ?? ''),
                'key' => (string) ($row['Key'] ?? ''),
                'extra' => (string) ($row['Extra'] ?? ''),
            ];
        }

        return $columns;
    }

    /**
     * 返回建表或建视图语句，不包含结尾分号。
     */
    public static function createStatement(Database $database, string $table, string $type = 'BASE TABLE'): string
    {
        $sql = $type === 'VIEW'
            ? 'SHOW CREATE VIEW ' . Identifier::quote($table)
            : 'SHOW CREATE TABLE ' . Identifier::quote($table);

        $rows = $database->query($sql);

        if ($rows === []) {
            return '';
        }

        $values = array_values($rows[0]);

        return rtrim((string) ($values[1] ?? ''), " \t\n\r\0\x0B;");
    }

    /**
     * 返回单列主键字段名，没有主键或联合主键时返回 null。
     *
     * @param array<int, array{name: string, type: string, key: string, extra: string}> $columns
     */
    public static function primaryColumn(array $columns): ?string
    {
        $primary = [];

        foreach ($columns as $column) {
            if ($column['key'] === 'PRI') {
                $primary[] = $column['name'];
            }
        }

        return count($primary) === 1 ? $primary[0] : null;
    }
}