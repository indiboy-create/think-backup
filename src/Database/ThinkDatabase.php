<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Database;

use think\db\ConnectionInterface;
use Throwable;
use Youvision\ThinkBackup\Database;
use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Support\Identifier;

/**
 * 基于 ThinkPHP 数据库连接的实现。
 *
 * 组件不直接使用 PDO 或 mysqli，所有 SQL 都经过 ThinkPHP 的连接执行，
 * 因此会复用项目的连接配置、日志、异常处理和事务设置。
 */
class ThinkDatabase implements Database
{
    protected ?string $connectionName;

    protected ?string $database;

    protected ?ConnectionInterface $connection = null;

    public function __construct(?string $connectionName = null, ?string $database = null)
    {
        $this->connectionName = $connectionName;
        $this->database = $database;
    }

    /**
     * 获取 ThinkPHP 连接实例。
     */
    public function connection(): ConnectionInterface
    {
        if ($this->connection === null) {
            try {
                $this->connection = \think\facade\Db::connect($this->connectionName ?? '');
            } catch (Throwable $e) {
                throw new BackupException('数据库连接失败：' . $e->getMessage(), 0, $e);
            }
        }

        return $this->connection;
    }

    /**
     * {@inheritDoc}
     */
    public function query(string $sql): array
    {
        try {
            $result = $this->connection()->query($sql);
        } catch (Throwable $e) {
            throw new BackupException('SQL 查询失败：' . $e->getMessage() . PHP_EOL . $sql, 0, $e);
        }

        return is_array($result) ? $result : [];
    }

    /**
     * {@inheritDoc}
     */
    public function execute(string $sql): int
    {
        try {
            return (int) $this->connection()->execute($sql);
        } catch (Throwable $e) {
            throw new BackupException('SQL 执行失败：' . $e->getMessage() . PHP_EOL . $sql, 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function config(string $name, $default = null)
    {
        try {
            $value = $this->connection()->getConfig($name);
        } catch (Throwable $e) {
            return $default;
        }

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * {@inheritDoc}
     */
    public function database(): string
    {
        if ($this->database !== null && $this->database !== '') {
            return $this->database;
        }

        $configured = $this->config('database');

        if (is_string($configured) && $configured !== '') {
            $this->database = $configured;

            return $this->database;
        }

        $rows = $this->query('SELECT DATABASE() AS `db`');
        $name = (string) ($rows[0]['db'] ?? '');

        if ($name === '') {
            throw new BackupException('无法确定当前数据库，请在配置或调用参数中指定 database。');
        }

        $this->database = $name;

        return $this->database;
    }

    /**
     * {@inheritDoc}
     */
    public function switchDatabase(string $database): void
    {
        Identifier::assertDatabase($database);

        $this->execute('USE ' . Identifier::quote($database));
        $this->database = $database;
    }

    /**
     * {@inheritDoc}
     */
    public function version(): string
    {
        $rows = $this->query('SELECT VERSION() AS `version`');

        return (string) ($rows[0]['version'] ?? '');
    }
}