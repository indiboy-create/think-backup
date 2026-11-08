<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Support\Identifier;
use Youvision\ThinkBackup\Support\Schema;
use Youvision\ThinkBackup\Support\SqlSplitter;

/**
 * 数据表维护。
 *
 * 提供原帝国备份王中的优化表、修复表、清空表、删除表、批量替换表前缀
 * 以及执行 SQL 文件等能力。
 */
final class Maintenance
{
    protected Database $database;

    public function __construct(Database $database, ?string $databaseName = null)
    {
        $this->database = $database;

        if ($databaseName !== null && $databaseName !== '') {
            $this->database->switchDatabase(Identifier::assertDatabase($databaseName));
        }
    }

    /**
     * 返回当前数据库的表列表。
     *
     * @return array<int, string>
     */
    public function tables(bool $includeViews = false): array
    {
        return array_map(
            static fn(array $table): string => $table['name'],
            Schema::tables($this->database, $includeViews)
        );
    }

    /**
     * 返回指定表的字段信息。
     *
     * @return array<int, array{name: string, type: string, key: string, extra: string}>
     */
    public function columns(string $table): array
    {
        return Schema::columns($this->database, Identifier::assertTable($table));
    }

    /**
     * 执行维护动作。
     *
     * @param array<int, string> $tables
     * @param array<string, string> $extra
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function action(string $action, array $tables, array $extra = []): array
    {
        $action = strtolower(trim($action));
        $tables = array_values(array_filter(array_map('trim', $tables), static fn(string $table): bool => $table !== ''));

        if ($tables === [] && $action !== 'list') {
            throw new BackupException('请指定要操作的表。');
        }

        $result = [];

        foreach ($tables as $table) {
            $table = Identifier::assertTable($table);
            $sql = $this->statement($action, $table, $extra);

            if ($sql === null) {
                if ($action === 'rename-prefix') {
                    continue;
                }

                throw new BackupException('不支持的表维护操作：' . $action);
            }

            $result[] = [
                'action' => $action,
                'table' => $table,
                'affected' => $this->database->execute($sql),
            ];
        }

        return $result;
    }

    /**
     * 执行一条 SQL。
     */
    public function execute(string $sql): int
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new BackupException('SQL 不能为空。');
        }

        return $this->database->execute($sql);
    }

    /**
     * 执行 SQL 文件，返回语句数量。
     */
    public function executeFile(string $file): int
    {
        if (!is_file($file)) {
            throw new BackupException('SQL 文件不存在：' . $file);
        }

        $splitter = new SqlSplitter();

        return $splitter->splitFile($file, function (string $sql): void {
            $this->database->execute($sql);
        });
    }

    /**
     * @param array<string, string> $extra
     */
    protected function statement(string $action, string $table, array $extra): ?string
    {
        $quoted = Identifier::quote($table);

        switch ($action) {
            case 'optimize':
                return 'OPTIMIZE TABLE ' . $quoted;

            case 'repair':
                return 'REPAIR TABLE ' . $quoted;

            case 'check':
                return 'CHECK TABLE ' . $quoted;

            case 'truncate':
                return 'TRUNCATE TABLE ' . $quoted;

            case 'drop':
                return 'DROP TABLE IF EXISTS ' . $quoted;

            case 'rename-prefix':
                $old = (string) ($extra['old_prefix'] ?? '');
                $new = (string) ($extra['new_prefix'] ?? '');

                if ($old === '') {
                    throw new BackupException('替换表前缀时必须指定 old-prefix。');
                }

                $renamed = str_replace($old, $new, $table);

                if ($renamed === $table) {
                    return null;
                }

                Identifier::assertTable($renamed);

                return 'RENAME TABLE ' . $quoted . ' TO ' . Identifier::quote($renamed);

            default:
                return null;
        }
    }
}