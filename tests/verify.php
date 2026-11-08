<?php
declare(strict_types=1);

use Youvision\ThinkBackup\Backup;

use Youvision\ThinkBackup\Dumper;
use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Maintenance;
use Youvision\ThinkBackup\Manifest;
use Youvision\ThinkBackup\Restorer;
use Youvision\ThinkBackup\Support\Archive;
use Youvision\ThinkBackup\Support\LegacyPhpReader;
use Youvision\ThinkBackup\Support\Path;
use Youvision\ThinkBackup\Support\SqlSplitter;
use Youvision\ThinkBackup\Tests\FakeDatabase;

require __DIR__ . '/bootstrap.php';

$failures = 0;
$assertions = 0;

function check(bool $condition, string $message): void
{
    global $failures, $assertions;

    $assertions++;

    if ($condition) {
        return;
    }

    $failures++;
    fwrite(STDERR, "[失败] " . $message . PHP_EOL);
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function same($expected, $actual, string $message): void
{
    check(
        $expected === $actual,
        $message . '，期望 ' . var_export($expected, true) . '，实际 ' . var_export($actual, true)
    );
}

function contains(string $needle, string $haystack, string $message): void
{
    check(str_contains($haystack, $needle), $message . '，未找到：' . $needle);
}

function throws(string $class, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        check($e instanceof $class, $message . '，抛出的是 ' . get_class($e));
        return;
    }

    check(false, $message . '，未抛出异常');
}

/**
 * @param array<string, mixed> $tables
 */
function fakeDatabase(array $tables): FakeDatabase
{
    return new FakeDatabase($tables, 'demo', 'utf8mb4');
}

/**
 * @return array<string, mixed>
 */
function demoTables(): array
{
    return [
        'tp_user' => [
            'columns' => [
                ['name' => 'id', 'type' => 'int(11)', 'key' => 'PRI', 'extra' => 'auto_increment'],
                ['name' => 'name', 'type' => 'varchar(50)', 'key' => '', 'extra' => ''],
                ['name' => 'avatar', 'type' => 'blob', 'key' => '', 'extra' => ''],
                ['name' => 'created_at', 'type' => 'datetime', 'key' => '', 'extra' => ''],
                ['name' => 'name_upper', 'type' => 'varchar(50)', 'key' => '', 'extra' => 'STORED GENERATED'],
            ],
            'rows' => [
                ['id' => 1, 'name' => "张三", 'avatar' => "\x89PNG\r\n", 'created_at' => '2026-01-01 00:00:00', 'name_upper' => '张三'],
                ['id' => 2, 'name' => "李四;ok", 'avatar' => "\x00\x01", 'created_at' => '2026-01-02 00:00:00', 'name_upper' => '李四;ok'],
                ['id' => 3, 'name' => "王五'quote", 'avatar' => '', 'created_at' => null, 'name_upper' => "王五'quote"],
                ['id' => 4, 'name' => '赵六', 'avatar' => null, 'created_at' => '2026-01-04 00:00:00', 'name_upper' => '赵六'],
                ['id' => 5, 'name' => '钱七', 'avatar' => 'abc', 'created_at' => '2026-01-05 00:00:00', 'name_upper' => '钱七'],
            ],
        ],
        'tp_log' => [
            'columns' => [
                ['name' => 'id', 'type' => 'int(11)', 'key' => 'PRI', 'extra' => 'auto_increment'],
                ['name' => 'message', 'type' => 'varchar(255)', 'key' => '', 'extra' => ''],
            ],
            'rows' => [
                ['id' => 1, 'message' => '登录'],
                ['id' => 2, 'message' => '退出'],
            ],
        ],
    ];
}

$root = Path::join(sys_get_temp_dir(), 'think-backup-test-' . uniqid());
Path::ensureDirectory($root);

try {
    // 1. 配置默认值
    $config = require dirname(__DIR__) . '/config/backup.php';
    same('size', $config['mode'], '默认分卷方式应为 size');
    same(2048, $config['size'], '默认分卷大小应为 2048 KB');
    check($config['path'] !== '', '配置应包含备份根目录');
    check(!isset($config['commands']), '配置应为纯方法调用模式，不再包含命令注册开关');

    // 2. SQL 拆分器
    $splitter = new SqlSplitter();
    $sql = <<<'SQL'
CREATE TABLE `a` (`id` int, `name` varchar(20));
INSERT INTO `a` VALUES (1, 'a;b'), (2, 'it''s'), (3, "x\"y");
-- 注释里的分号 ; 不应拆分
/* 块注释 ; 也不应拆分 */
INSERT INTO `a` VALUES (4, 'ok');
SET NAMES 'utf8mb4';
SQL;
    $statements = $splitter->split($sql);
    same(4, count($statements), 'SQL 拆分器应按分号拆分且忽略引号内分号');
    contains("INSERT INTO `a` VALUES (1, 'a;b')", $statements[1], '第一条 INSERT 应完整');
    same("SET NAMES 'utf8mb4'", $statements[3], '最后一条 SET 应完整');

    $delimiterSql = "DELIMITER $$\nCREATE PROCEDURE `p`() BEGIN SELECT 1; SELECT 2; END$$\nDELIMITER ;\nINSERT INTO `a` VALUES (5, 'ok');\n";
    $delimiterStatements = $splitter->split($delimiterSql);
    same(2, count($delimiterStatements), 'DELIMITER 应正确切换语句结束符');
    contains('SELECT 2;', $delimiterStatements[0], 'DELIMITER 内的分号应保留');

    $bigFile = Path::join($root, 'split.sql');
    file_put_contents($bigFile, "-- " . str_repeat('x', 70000) . "\nINSERT INTO `a` VALUES (6, 'big');\n");
    $fromFile = [];
    $count = (new SqlSplitter())->splitFile($bigFile, static function (string $statement) use (&$fromFile): void {
        $fromFile[] = $statement;
    });
    same(1, $count, '分块读取大文件应得到一条语句');
    contains("INSERT INTO `a` VALUES (6, 'big')", $fromFile[0], '分块读取内容应完整');

    // 3. 旧版 PHP 备份文件解析
    $legacyDirectory = Path::join($root, 'legacy');
    Path::ensureDirectory($legacyDirectory);
    $legacyData = <<<'PHP'
<?php
define('InEmpireBakData',TRUE);
require("../../inc/header.php");
DoSetDbChar('utf8mb4');
E_C("CREATE TABLE `tp_user` (`id` int(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
E_D("insert into `tp_user` values(1,'张三');");
E_D("insert into `tp_user` values(2,'It''s ok');");
require("../../inc/footer.php");
?>
PHP;
    file_put_contents(Path::join($legacyDirectory, 'tp_user_1.php'), $legacyData);
    $reader = new LegacyPhpReader();
    $legacyStatements = $reader->read(Path::join($legacyDirectory, 'tp_user_1.php'));
    same(4, count($legacyStatements), '旧版文件应解析出 SET NAMES、建表和两条插入');
    contains('SET NAMES', $legacyStatements[0], 'DoSetDbChar 应转换为 SET NAMES');
    contains("'张三'", $legacyStatements[2], '旧版中文内容应正确解析');
    contains("It''s ok", $legacyStatements[3], '旧版成对单引号应保留');

    $legacyConfig = <<<'PHP'
<?php
$b_table="tp_user,tp_log";
$tb['tp_user']=1;
$tb['tp_log']=1;
$b_baktype=1;
$b_filesize=2048;
$b_bakline=100;
$b_dbname="legacy_db";
$b_dbchar="utf8mb4";
$b_beover=1;
$b_insertf="replace";
$b_bakdatatype=0;
?>
PHP;
    file_put_contents(Path::join($legacyDirectory, 'config.php'), $legacyConfig);
    file_put_contents(Path::join($legacyDirectory, 'tp_log_1.php'), "<?php\nE_D(\"insert into `tp_log` values(1,'log');\");\n");
    $legacyManifest = Manifest::load($legacyDirectory);
    same('legacy_db', $legacyManifest['database'], '旧版配置应解析出数据库名');
    same('line', $legacyManifest['mode'], '旧版配置应识别按记录分卷');
    same(2, count($legacyManifest['tables']), '旧版配置应识别两张表');
    same(1, count($legacyManifest['tables'][0]['files']), '旧版配置应扫描到分卷文件');

    // 4. 生成备份
    $source = fakeDatabase(demoTables());
    $dumper = new Dumper($source, [
        'name' => 'unit',
        'mode' => 'line',
        'line' => 2,
        'batch' => 2,
        'insert' => 'replace',
        'hex' => 'blob',
        'ignore_auto_increment' => ['tp_user.id'],
        'charset' => 'utf8mb4',
        'complete_insert' => true,
        'count_exact' => false,
    ]);
    $result = $dumper->run($root);
    same('unit', $result['name'], '备份名称应为 unit');
    same(2, count($result['tables']), '备份应包含两张表');
    same(7, $result['rows'], '备份记录总数应为 7');

    $byName = [];
    foreach ($result['tables'] as $table) {
        $byName[$table['name']] = $table;
    }

    check(isset($byName['tp_user'], $byName['tp_log']), '备份清单应包含 tp_user 与 tp_log');
    same(3, count($byName['tp_user']['files']), '5 条用户记录按每卷 2 条应生成 3 个文件');
    check(is_file(Path::join($root, 'unit', 'backup.json')), '应生成 backup.json');
    check(is_file(Path::join($root, 'unit', 'readme.txt')), '应生成 readme.txt');

    $manifest = Manifest::load(Path::join($root, 'unit'));
    same('demo', $manifest['database'], '清单数据库名应为 demo');
    same('utf8mb4', $manifest['charset'], '清单字符集应为 utf8mb4');

    $manifestByName = [];
    foreach ($manifest['tables'] as $table) {
        $manifestByName[$table['name']] = $table;
    }

    same(5, $manifestByName['tp_user']['rows'], '用户表记录数应为 5');

    $userSql = (string) file_get_contents(Path::join($root, 'unit', 'tp_user_1.sql'));
    contains("SET NAMES 'utf8mb4'", $userSql, '备份文件应包含字符集设置');
    contains('SET FOREIGN_KEY_CHECKS=0', $userSql, '备份文件应包含外键关闭设置');
    contains('REPLACE INTO `tp_user`', $userSql, '应使用 REPLACE INTO');
    contains('(`id`,`name`,`avatar`,`created_at`)', $userSql, '应写出完整字段名');
    contains('NULL', $userSql, '自增字段应写为 NULL');
    contains('0x', $userSql, 'blob 字段应输出十六进制');
    check(!str_contains($userSql, 'name_upper'), '生成列不应写入备份');

    $allUserSql = '';
    foreach (glob(Path::join($root, 'unit', 'tp_user_*.sql')) as $file) {
        $allUserSql .= (string) file_get_contents($file);
    }
    preg_match_all('/REPLACE INTO `tp_user`/', $allUserSql, $matches);
    same(5, count($matches[0]), '用户表应生成 5 条 REPLACE 语句');

    // 5. 恢复备份
    $target = new FakeDatabase([], 'target');
    $restorer = new Restorer($target, ['database' => 'target']);
    $restoreResult = $restorer->run($root, 'unit');
    same('target', $restoreResult['database'], '恢复目标数据库应为 target');
    same(2, count($restoreResult['tables']), '恢复应处理两张表');
    check(in_array('SET FOREIGN_KEY_CHECKS=0', $target->executed, true), '恢复前应关闭外键检查');
    check(in_array('SET FOREIGN_KEY_CHECKS=1', $target->executed, true), '恢复后应恢复外键检查');
    $replaceCount = 0;
    foreach ($target->executed as $sql) {
        if (str_starts_with($sql, 'REPLACE INTO')) {
            $replaceCount++;
        }
    }
    same(7, $replaceCount, '恢复应执行 7 条 REPLACE 语句');

    $legacyTarget = new FakeDatabase([], 'legacy_target');
    $legacyRestorer = new Restorer($legacyTarget, ['database' => 'legacy_target']);
    $legacyResult = $legacyRestorer->run($root, 'legacy');
    same('legacy_target', $legacyResult['database'], '旧版备份应恢复到指定数据库');
    check(in_array("SET NAMES 'utf8mb4'", $legacyTarget->executed, true), '旧版备份应执行 SET NAMES');

    // 6. 路径安全
    throws(
        BackupException::class,
        static fn() => (new Restorer(new FakeDatabase()))->run($root, '../outside'),
        '恢复时应拒绝路径穿越'
    );
    check(!Path::within($root, Path::join($root, '../outside')), 'Path::within 应拒绝上级目录');

    // 7. 管理器列表、查找、压缩、清理与删除
    $manager = new Backup(['path' => $root, 'zip_level' => 6], $source);
    $backups = $manager->backups();
    check(count($backups) >= 1, '备份列表应至少包含 unit');
    same('unit', $manager->find('unit')['name'], 'find 应返回 unit');
    same('demo', $manager->find('unit')['database'], 'find 应返回清单数据库名');
    same(7, $manager->find('unit')['rows'], 'find 应统计记录数');

    if (class_exists(ZipArchive::class)) {
        $zip = $manager->pack('unit');
        check(is_file($zip), '应生成 zip 压缩包');
        $extract = Path::join($root, 'extracted');
        Archive::extract($zip, $extract);
        check(is_file(Path::join($extract, 'backup.json')), 'zip 解压后应包含备份清单');
        Path::remove($extract);
        @unlink($zip);
    }

    $old = Path::join($root, 'old');
    Path::ensureDirectory($old);
    file_put_contents(Path::join($old, 'note.txt'), 'old');
    @touch($old, time() - 86400 * 10);
    $toDelete = $manager->clean(7, true);
    check(in_array('old', $toDelete, true), '清理应列出 10 天前的备份');
    $manager->clean(7, false);
    check(!is_dir($old), '清理应删除过期备份');

    check($manager->delete('unit'), '删除备份应成功');
    check(!is_dir(Path::join($root, 'unit')), '删除后备份目录不应存在');
    throws(BackupException::class, static fn() => $manager->find('unit'), '删除后 find 应抛出异常');

    // 8. 表维护
    $maintenanceDb = fakeDatabase(demoTables());
    $maintenance = new Maintenance($maintenanceDb, 'demo');
    same(['tp_log', 'tp_user'], $maintenance->tables(), '表维护应列出两张表');
    $maintenance->action('optimize', ['tp_user']);
    $maintenance->action('repair', ['tp_log']);
    $maintenance->action('truncate', ['tp_log']);
    $maintenance->action('drop', ['tp_log']);
    $maintenance->action('rename-prefix', ['tp_user'], ['old_prefix' => 'tp_', 'new_prefix' => 'app_']);
    check(in_array('OPTIMIZE TABLE `tp_user`', $maintenanceDb->executed, true), '应执行 OPTIMIZE TABLE');
    check(in_array('REPAIR TABLE `tp_log`', $maintenanceDb->executed, true), '应执行 REPAIR TABLE');
    check(in_array('TRUNCATE TABLE `tp_log`', $maintenanceDb->executed, true), '应执行 TRUNCATE TABLE');
    check(in_array('DROP TABLE IF EXISTS `tp_log`', $maintenanceDb->executed, true), '应执行 DROP TABLE');
    check(in_array('RENAME TABLE `tp_user` TO `app_user`', $maintenanceDb->executed, true), '应执行 RENAME TABLE');

    $sqlFile = Path::join($root, 'maintenance.sql');
    file_put_contents($sqlFile, "SET NAMES 'utf8mb4';\nINSERT INTO `tp_log` VALUES (3, 'file');\n");
    same(2, $maintenance->executeFile($sqlFile), 'SQL 文件应执行两条语句');
    same(1, $maintenance->execute('DELETE FROM `tp_log` WHERE `id` = 3'), 'execute 应返回影响行数');

    // 9. 管理器提供的数据库、字段与表维护方法
    $apiDatabase = fakeDatabase(demoTables());
    $api = new Backup(['path' => $root], $apiDatabase);
    same(['demo', 'information_schema', 'mysql'], $api->databases(), 'databases() 应返回数据库列表');
    check($api->createDatabase('new_db', 'utf8mb4'), 'createDatabase() 应执行成功');
    check(in_array('CREATE DATABASE IF NOT EXISTS `new_db` DEFAULT CHARACTER SET utf8mb4', $apiDatabase->executed, true), 'createDatabase() 应执行建库 SQL');
    check($api->dropDatabase('new_db'), 'dropDatabase() 应执行成功');
    check(in_array('DROP DATABASE IF EXISTS `new_db`', $apiDatabase->executed, true), 'dropDatabase() 应执行删库 SQL');

    $columns = $api->columns('tp_user');
    same('id', $columns[0]['name'], 'columns() 应返回字段列表');
    same('int(11)', $columns[0]['type'], 'columns() 应返回字段类型');

    $api->optimize('tp_user');
    check(in_array('OPTIMIZE TABLE `tp_user`', $apiDatabase->executed, true), 'optimize() 应优化单个表');
    $api->repair(['tp_user', 'tp_log']);
    check(in_array('REPAIR TABLE `tp_user`', $apiDatabase->executed, true), 'repair() 应支持表数组');
    $api->check('tp_log');
    check(in_array('CHECK TABLE `tp_log`', $apiDatabase->executed, true), 'check() 应检查表');
    $api->truncate('tp_log');
    check(in_array('TRUNCATE TABLE `tp_log`', $apiDatabase->executed, true), 'truncate() 应清空表');
    $api->dropTable('tp_log');
    check(in_array('DROP TABLE IF EXISTS `tp_log`', $apiDatabase->executed, true), 'dropTable() 应删除表');
    $api->renameTablePrefix('tp_user', 'tp_', 'app_');
    check(in_array('RENAME TABLE `tp_user` TO `app_user`', $apiDatabase->executed, true), 'renameTablePrefix() 应替换表前缀');

    // 10. 服务注册
    $serviceApp = new \think\App(Path::join($root, 'service-app'));
    $serviceApp->config->set(['path' => $root], 'backup');
    $service = new \Youvision\ThinkBackup\Service($serviceApp);
    $service->register();
    $registered = $serviceApp->make(Backup::class);
    check($registered instanceof Backup, '服务应注册 Backup 管理器');
    same(Path::normalize($root), $registered->path(), '服务应合并项目配置中的备份路径');
    check(think_backup() instanceof Backup, 'think_backup() 助手应返回管理器');

    // 11. ThinkPHP 数据库连接对象创建（不发起实际连接）
    $databaseApp = new \think\App(Path::join($root, 'database-app'));
    $databaseApp->config->set([
        'default' => 'file',
        'stores' => [
            'file' => [
                'type' => 'file',
                'path' => Path::join($root, 'cache'),
            ],
        ],
    ], 'cache');
    $databaseApp->config->set([
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'type' => 'mysql',
                'hostname' => '127.0.0.1',
                'hostport' => '3306',
                'database' => 'demo',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
        ],
    ], 'database');
    $thinkDatabase = new \Youvision\ThinkBackup\Database\ThinkDatabase('mysql');
    check(
        $thinkDatabase->connection() instanceof \think\db\ConnectionInterface,
        'ThinkDatabase 应通过 ThinkPHP Db 门面创建连接对象'
    );
    same('utf8mb4', $thinkDatabase->config('charset'), 'ThinkDatabase 应读取连接字符集配置');
} finally {
    Path::remove($root);
}

echo $failures === 0
    ? '全部通过，共 ' . $assertions . ' 项断言。' . PHP_EOL
    : '失败 ' . $failures . ' 项，共 ' . $assertions . ' 项断言。' . PHP_EOL;

exit($failures === 0 ? 0 : 1);