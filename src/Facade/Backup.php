<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Facade;

use think\Facade;
use Youvision\ThinkBackup\Backup as BackupManager;
use Youvision\ThinkBackup\Maintenance;

/**
 * 备份门面。
 *
 * @method static array run(array $options = [], ?callable $progress = null)
 * @method static array restore(string $name, array $options = [], ?callable $progress = null)
 * @method static array backups()
 * @method static array find(string $name)
 * @method static bool delete(string $name)
 * @method static string pack(string $name)
 * @method static array clean(int $days = 0, bool $dryRun = false)
 * @method static Maintenance maintenance(array $options = [])
 * @method static array databases(array $options = [])
 * @method static bool createDatabase(string $name, string $charset = '', array $options = [])
 * @method static bool dropDatabase(string $name, array $options = [])
 * @method static array tables(array $options = [])
 * @method static array columns(string $table, array $options = [])
 * @method static array optimize(array|string $tables, array $options = [])
 * @method static array repair(array|string $tables, array $options = [])
 * @method static array check(array|string $tables, array $options = [])
 * @method static array truncate(array|string $tables, array $options = [])
 * @method static array dropTable(array|string $tables, array $options = [])
 * @method static array renameTablePrefix(array|string $tables, string $oldPrefix, string $newPrefix, array $options = [])
 * @method static int executeSql(string $sql, array $options = [])
 * @method static int executeSqlFile(string $file, array $options = [])
 * @method static mixed config(?string $name = null, mixed $default = null)
 * @method static string path()
 *
 * @see BackupManager
 */
class Backup extends Facade
{
    protected static function getFacadeClass(): string
    {
        return BackupManager::class;
    }
}