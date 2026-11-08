<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use Youvision\ThinkBackup\Exception\BackupException;

/**
 * SQL 标识符与名称处理。
 *
 * 表名、字段名、数据库名都来自数据库元数据或用户输入，拼接 SQL 前必须
 * 使用反引号包裹并转义反引号。文件名另行处理，避免路径穿越。
 */
final class Identifier
{
    /**
     * 使用反引号包裹标识符。
     */
    public static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * 校验并返回数据库名。
     */
    public static function assertDatabase(string $name): string
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > 64 || preg_match('/[\x00-\x1f\/\\\\]/u', $name)) {
            throw new BackupException('数据库名不合法：' . $name);
        }

        return $name;
    }

    /**
     * 校验并返回表名。
     */
    public static function assertTable(string $name): string
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > 64 || preg_match('/[\x00-\x1f\/\\\\]/u', $name)) {
            throw new BackupException('表名不合法：' . $name);
        }

        return $name;
    }

    /**
     * 转义 SQL 字符串字面量。
     */
    public static function literal(string $value): string
    {
        return str_replace(
            ["\\", "\0", "\n", "\r", "'", "\x1a"],
            ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z"],
            $value
        );
    }

    /**
     * 将表名转换为可安全用于文件名的字符串。
     */
    public static function fileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_$.-]+/u', '_', $name) ?? '';
        $name = trim($name, '.');
        $name = str_replace('..', '_', $name);

        return $name !== '' ? $name : 'table';
    }

    /**
     * 将逗号分隔字符串或数组统一为字符串数组。
     *
     * @param array<int, string>|string|null $value
     * @return array<int, string>
     */
    public static function list($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);
        $result = [];

        foreach ($items as $item) {
            $item = trim((string) $item);

            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * 解析 "表名.字段名" 形式的自增忽略配置。
     *
     * @param array<int, string>|string|null $value
     * @return array<string, array<int, string>>
     */
    public static function autoIncrementMap($value): array
    {
        $map = [];

        foreach (self::list($value) as $item) {
            $position = strrpos($item, '.');

            if ($position === false) {
                continue;
            }

            $table = substr($item, 0, $position);
            $column = substr($item, $position + 1);

            if ($table === '' || $column === '') {
                continue;
            }

            $map[$table][] = $column;
        }

        return $map;
    }
}