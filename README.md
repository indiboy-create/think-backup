## ThinkPHP-PanGu - 盘古ThinkPHP开源索引!
面向 ThinkPHP 的开源工程索引：组件、框架、服务与发布工具，按能力组织，持续整理。
仓库地址：**<https://github.com/indiboy-create/ThinkPHP-PanGu/>**
在线站点：**<https://indiboy-create.github.io/ThinkPHP-PanGu/>**

# youvision/think-backup

ThinkPHP 8 数据库备份与恢复组件，基于帝国备份王（EmpireBak）的备份思路重写，使用 ThinkPHP 数据库连接执行，支持结构/数据导出、按大小或记录数分卷、zip 压缩、数据库与数据表维护、旧版备份兼容。

组件只提供 PHP 方法调用，所有能力都可以通过门面、依赖注入或 `think_backup()` 助手在控制器、服务层、定时任务中调用。

## 功能特性

- 备份 MySQL / MariaDB 表结构与数据，可分别关闭结构或数据
- 按文件大小（KB）或记录条数分卷，单卷可单独执行
- 支持 `INSERT` 与 `REPLACE` 两种写入方式
- 可选择写出完整字段名，表结构变化时更安全
- 自动识别 blob、binary、bit、geometry 等二进制字段并输出十六进制
- 自动跳过虚拟生成列，避免恢复时报错
- 可选择忽略自增字段，由目标库重新分配自增 ID
- 备份目录生成 `backup.json` 清单与 `readme.txt` 说明
- 备份完成后可生成 zip 压缩包
- 恢复时直接从目录或 zip 读取，按分卷顺序执行
- 兼容旧版帝国备份王的 `config.php` + `table_1.php` 备份文件，只解析字符串，不执行备份文件中的代码
- 提供数据库列表、建库、删库、表列表、字段列表、优化表、修复表、检查表、清空表、删除表、批量替换表前缀、执行 SQL 文件等方法
- 提供严格类型声明、路径限制与 SQL 标识符转义

## 环境要求

- PHP >= 8.0
- ThinkPHP >= 8.0
- MySQL 5.6+ / MariaDB 10+
- zip 扩展可选，用于压缩与从 zip 恢复

## 安装

```bash
composer require youvision/think-backup
```

包通过 `extra.think.services` 声明服务，安装后执行服务发现，并在需要时将配置发布到项目：

```bash
php think service:discover
php think vendor:publish
```

执行后项目中会出现 `config/backup.php`。

## 配置

主要配置项：

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `path` | `runtime/backup` | 备份根目录，必须可写 |
| `connection` | 空 | 数据库连接名，对应 `config/database.php` 中的 connections |
| `database` | 空 | 数据库名，空表示使用连接配置或当前库 |
| `prefix` | 空 | 只备份该前缀的表 |
| `tables` | `[]` | 默认备份的表，空表示全部 |
| `exclude` | `[]` | 排除的表 |
| `structure` | `true` | 是否导出结构 |
| `data` | `true` | 是否导出数据 |
| `mode` | `size` | 分卷方式：`size` / `line` |
| `size` | `2048` | 单卷大小上限，单位 KB |
| `line` | `1000` | 单卷最大记录数 |
| `batch` | `1000` | 每次读取数据库的记录数 |
| `insert` | `insert` | `insert` 或 `replace` |
| `complete_insert` | `true` | 是否写出字段名 |
| `hex` | `blob` | `none` / `blob` / `all` |
| `ignore_auto_increment` | `[]` | 忽略的自增字段，格式 `表名.字段名` |
| `charset` | `auto` | 备份文件字符集，`auto` 读取库默认字符集 |
| `zip` | `false` | 备份完成后是否生成 zip |
| `zip_level` | `6` | 压缩级别 0-9 |
| `zip_keep_directory` | `true` | 生成 zip 后是否保留原目录 |
| `permissions` | `0755` | 新建目录权限 |
| `time_limit` | `0` | PHP 执行超时秒数，0 表示不修改 |
| `count_exact` | `false` | 是否用 `COUNT(*)` 精确统计记录数 |
| `include_views` | `false` | 是否导出视图 |
| `retain_days` | `0` | `clean()` 默认保留天数 |

完整中文说明见 `config/backup.php`。

## 调用方式

### 门面

```php
use Youvision\ThinkBackup\Facade\Backup;

$result = Backup::run(['database' => 'shop']);
$tables = Backup::tables();
```

### 助手函数

```php
$result = think_backup()->run(['database' => 'shop']);
$tables = think_backup()->tables();
```

### 依赖注入

```php
use Youvision\ThinkBackup\Backup;

class BackupController
{
    public function __construct(protected Backup $backup)
    {
    }

    public function index()
    {
        return json($this->backup->backups());
    }
}
```

## 方法操作说明

以下方法均位于 `Youvision\ThinkBackup\Backup`，可通过 `Backup::方法名()`、`think_backup()->方法名()` 或注入的 `$backup` 实例调用。除特别说明外，`$options` 可传入 `connection`、`database` 等局部覆盖配置。

### 备份与恢复

#### `run(array $options = [], ?callable $progress = null): array`

备份数据库。

```php
$result = Backup::run([
    'database' => 'shop',
    'prefix' => 'tp_',
    'exclude' => ['tp_log'],
    'mode' => 'line',
    'line' => 1000,
    'insert' => 'replace',
    'ignore_auto_increment' => ['tp_user.id'],
    'zip' => true,
]);
```

`$result` 包含：

```php
[
    'name' => 'shop_20261108120000_ab12cd',
    'path' => '/project/runtime/backup/shop_20261108120000_ab12cd',
    'zip' => '/project/runtime/backup/shop_20261108120000_ab12cd.zip',
    'database' => 'shop',
    'charset' => 'utf8mb4',
    'tables' => [
        ['name' => 'tp_user', 'rows' => 1000, 'files' => ['tp_user_1.sql', 'tp_user_2.sql']],
    ],
    'rows' => 1000,
    'files' => 2,
    'elapsed' => 0.42,
]
```

进度回调：

```php
Backup::run([], function (array $event) {
    // ['type' => 'table', 'table' => 'tp_user', 'rows' => 1000, 'files' => 2]
});
```

#### `restore(string $name, array $options = [], ?callable $progress = null): array`

恢复数据库。`$name` 是备份目录名或 zip 文件名，必须位于配置的 `path` 下。

```php
// 恢复到备份清单记录的数据库
Backup::restore('shop_20261108120000_ab12cd');

// 恢复到另一个数据库，不存在时自动创建
Backup::restore('shop_20261108120000_ab12cd', [
    'database' => 'shop_test',
    'create_database' => true,
]);

// 只恢复部分表
Backup::restore('shop_20261108120000_ab12cd.zip', [
    'tables' => ['tp_user', 'tp_order'],
]);
```

恢复会覆盖同名表，请在业务层确认后再调用，或加权限校验。

### 备份管理

| 方法 | 说明 | 示例 |
| --- | --- | --- |
| `backups(): array` | 列出全部备份，按时间倒序 | `Backup::backups()` |
| `find(string $name): array` | 查找单个备份信息 | `Backup::find('shop_20261108120000_ab12cd')` |
| `delete(string $name): bool` | 删除备份目录及同名 zip | `Backup::delete('shop_20261108120000_ab12cd')` |
| `pack(string $name): string` | 将已有备份目录压缩为 zip | `Backup::pack('shop_20261108120000_ab12cd')` |
| `clean(int $days = 0, bool $dryRun = false): array` | 清理过期备份，返回被删除或将被删除的名称 | `Backup::clean(7, true)` |
| `path(): string` | 返回备份根目录 | `Backup::path()` |
| `config(?string $name = null, $default = null)` | 读取组件配置 | `Backup::config('path')` |

`backups()` 每项包含 `name`、`path`、`zip`、`database`、`created_at`、`tables`、`rows`、`size`、`size_text`、`timestamp`、`manifest`。

### 数据库管理

| 方法 | 说明 | 示例 |
| --- | --- | --- |
| `databases(array $options = []): array` | 列出当前连接可见的数据库 | `Backup::databases()` |
| `createDatabase(string $name, string $charset = '', array $options = []): bool` | 创建数据库 | `Backup::createDatabase('shop_test', 'utf8mb4')` |
| `dropDatabase(string $name, array $options = []): bool` | 删除数据库，谨慎调用 | `Backup::dropDatabase('shop_test')` |

### 表与字段

#### `tables(array $options = []): array`

列出当前数据库的全部基础表。可选参数：

```php
// 指定连接和数据库
$tables = Backup::tables([
    'connection' => 'mysql',
    'database' => 'shop',
]);

// 同时列出视图
$tables = Backup::tables(['include_views' => true]);
```

#### `columns(string $table, array $options = []): array`

列出单个表的字段信息：

```php
$columns = Backup::columns('tp_user');

// [
//     ['name' => 'id', 'type' => 'int(11)', 'key' => 'PRI', 'extra' => 'auto_increment'],
//     ['name' => 'name', 'type' => 'varchar(50)', 'key' => '', 'extra' => ''],
// ]
```

### 表维护

单表操作和多表操作使用同一组方法，表参数支持字符串或字符串数组。

| 方法 | 说明 | 示例 |
| --- | --- | --- |
| `optimize($tables, array $options = [])` | 优化表 | `Backup::optimize('tp_user')` |
| `repair($tables, array $options = [])` | 修复表 | `Backup::repair(['tp_user', 'tp_log'])` |
| `check($tables, array $options = [])` | 检查表 | `Backup::check('tp_log')` |
| `truncate($tables, array $options = [])` | 清空表 | `Backup::truncate('tp_log')` |
| `dropTable($tables, array $options = [])` | 删除表 | `Backup::dropTable('tp_log')` |
| `renameTablePrefix($tables, string $oldPrefix, string $newPrefix, array $options = [])` | 批量替换表前缀 | `Backup::renameTablePrefix(['tp_user', 'tp_log'], 'tp_', 'app_')` |

返回值格式：

```php
[
    ['action' => 'optimize', 'table' => 'tp_user', 'affected' => 0],
]
```

`Maintenance` 实例也直接开放了 `action()`、`tables()`、`columns()`、`execute()`、`executeFile()`：

```php
$maintenance = Backup::maintenance(['database' => 'shop']);

$maintenance->tables();
$maintenance->columns('tp_user');
$maintenance->action('optimize', ['tp_user']);
$maintenance->action('rename-prefix', ['tp_user'], [
    'old_prefix' => 'tp_',
    'new_prefix' => 'app_',
]);
```

### 执行 SQL

| 方法 | 说明 | 示例 |
| --- | --- | --- |
| `executeSql(string $sql, array $options = [])` | 执行单条 SQL，返回影响行数 | `Backup::executeSql('DELETE FROM tp_log WHERE id = 3')` |
| `executeSqlFile(string $file, array $options = [])` | 执行 SQL 文件，返回语句数量 | `Backup::executeSqlFile('/path/to/update.sql')` |

`executeSqlFile()` 使用内置 SQL 拆分器，支持引号、反引号、注释和 `DELIMITER` 指令。

## 备份文件结构

```text
runtime/backup/shop_20261108120000_ab12cd/
├── backup.json      备份清单：数据库、字符集、表、行数、分卷文件
├── readme.txt       中文说明
├── tp_user_1.sql
├── tp_user_2.sql
└── tp_log_1.sql
```

每个 SQL 文件头部包含：

```sql
SET NAMES 'utf8mb4';
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS=0;
```

因此任意一卷都可以单独执行。恢复器会在全部文件执行完成后统一恢复 `FOREIGN_KEY_CHECKS=1`。

## 旧版帝国备份王兼容

把旧版备份目录放入 `path` 指向的备份根目录，即可直接恢复：

```php
Backup::restore('shop_20240101120000');
```

组件会读取旧版 `config.php` 中的 `$b_table`、`$b_dbname`、`$b_dbchar`、`$b_baktype` 等配置，并扫描 `table_1.php`、`table_2.php` 等文件。旧版 PHP 文件通过 `token_get_all` 解析 `E_D()`、`E_C()`、`DoSetDbChar()` 中的字符串字面量，不会 `include` 或执行备份文件，避免任意代码执行风险。

## 安全说明

- 所有备份名称、目录名、zip 条目都会校验在备份根目录内，拒绝 `../`、绝对路径和盘符。
- 表名、字段名、数据库名使用反引号包裹并转义。
- 数据值使用 SQL 字符串转义；二进制字段优先十六进制输出。
- `restore()`、`dropTable()`、`dropDatabase()`、`truncate()` 会修改或删除数据，请在业务层做好权限控制与二次确认。
- 备份目录可能包含完整业务数据，请确保 `runtime/backup` 不在 Web 可访问目录，或通过 Nginx/Apache 禁止访问。

## 限制

- 只支持 MySQL / MariaDB。
- 默认不导出视图；视图可使用 `include_views` 开启。
- 不导出触发器、存储过程、函数和事件。
- 备份期间不会锁表，数据量大且业务持续写入时，备份不是严格的一致性快照，建议在低峰期执行。
- 恢复 DDL 无法回滚，请先确认目标数据库。
- `backup.json` 是恢复依据，请勿手动修改或删除。

## 测试

```bash
composer test
```

测试覆盖 SQL 拆分、旧版 PHP 备份解析、分卷生成、恢复、路径安全、清单读写、数据库/表/字段方法、表维护、服务注册与配置合并。测试使用内存假数据库，不需要连接 MySQL。

## License

MIT