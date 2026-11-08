<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

/**
 * 数据库访问抽象。
 *
 * 默认实现基于 ThinkPHP 的数据库连接；测试或特殊场景可以注入其它实现，
 * 从而在不启动框架的情况下验证备份文件生成与恢复流程。
 */
interface Database
{
    /**
     * 执行查询并返回关联数组结果集。
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql): array;

    /**
     * 执行写入或 DDL 语句，返回受影响行数。
     */
    public function execute(string $sql): int;

    /**
     * 读取连接配置，例如 database、prefix、charset。
     *
     * @param mixed $default
     * @return mixed
     */
    public function config(string $name, $default = null);

    /**
     * 当前数据库名。
     */
    public function database(): string;

    /**
     * 切换当前数据库。
     */
    public function switchDatabase(string $database): void;

    /**
     * 数据库服务端版本号，例如 8.0.36。
     */
    public function version(): string;
}