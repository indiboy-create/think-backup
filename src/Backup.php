<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

use Throwable;
use ZipArchive;
use Youvision\ThinkBackup\Database\ThinkDatabase;
use Youvision\ThinkBackup\Exception\BackupException;
use Youvision\ThinkBackup\Support\Archive;
use Youvision\ThinkBackup\Support\Identifier;
use Youvision\ThinkBackup\Support\Path;
use Youvision\ThinkBackup\Support\Schema;

/**
 * 备份管理器。
 *
 * 对 Dumper、Restorer、Maintenance 进行统一封装，供门面、依赖注入
 * 与 think_backup() 助手调用。
 */
class Backup
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected ?Database $database;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?Database $database = null)
    {
        $this->config = array_replace(self::defaults(), $config);
        $this->database = $database;
    }

    /**
     * 包内默认配置。
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        static $defaults = null;

        if ($defaults === null) {
            $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'backup.php';
            $loaded = is_file($file) ? require $file : [];
            $defaults = is_array($loaded) ? $loaded : [];
        }

        return $defaults;
    }

    /**
     * 执行数据库备份。
     *
     * @param array<string, mixed> $options
     * @param callable(array<string, mixed>): void|null $progress
     * @return array<string, mixed>
     */
    public function run(array $options = [], ?callable $progress = null): array
    {
        $this->applyTimeLimit();
        $options = $this->options($options);
        $database = $this->database($options);
        $dumper = new Dumper($database, $options);

        return $dumper->run($this->path(), $progress);
    }

    /**
     * 执行数据库恢复。
     *
     * @param array<string, mixed> $options
     * @param callable(array<string, mixed>): void|null $progress
     * @return array<string, mixed>
     */
    public function restore(string $name, array $options = [], ?callable $progress = null): array
    {
        $this->applyTimeLimit();
        $options = $this->options($options);
        $database = $this->database($options);
        $restorer = new Restorer($database, $options);

        return $restorer->run($this->path(), $name, $progress);
    }

    /**
     * 返回全部备份列表，按时间倒序。
     *
     * @return array<int, array<string, mixed>>
     */
    public function backups(): array
    {
        $root = $this->path();

        if (!is_dir($root)) {
            return [];
        }

        $entries = [];
        $names = [];
        $files = @scandir($root);

        if ($files === false) {
            throw new BackupException('无法读取备份根目录：' . $root);
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
                continue;
            }

            $directory = Path::join($root, $file);

            if (is_dir($directory)) {
                $entries[] = $this->entry($file, $directory);
                $names[$file] = true;
            }
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with(strtolower($file), '.zip')) {
                continue;
            }

            $name = substr($file, 0, -4);

            if (isset($names[$name])) {
                continue;
            }

            $zip = Path::join($root, $file);

            if (!is_file($zip)) {
                continue;
            }

            $manifest = $this->zipManifest($zip);
            $entries[] = $this->makeEntry($name, null, $zip, $manifest);
        }

        usort($entries, static fn(array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);

        return $entries;
    }

    /**
     * 查找单个备份。
     *
     * @return array<string, mixed>
     */
    public function find(string $name): array
    {
        $root = $this->path();
        $name = trim($name);
        $directory = Path::join($root, $name);
        $zip = Path::join($root, $name . '.zip');

        if (!Path::within($root, $directory) || !Path::within($root, $zip)) {
            throw new BackupException('备份名称不合法：' . $name);
        }

        if (is_dir($directory)) {
            return $this->entry($name, $directory);
        }

        if (Archive::isZip($zip)) {
            return $this->makeEntry($name, null, $zip, $this->zipManifest($zip));
        }

        throw new BackupException('备份不存在：' . $name);
    }

    /**
     * 删除备份目录及同名压缩包。
     */
    public function delete(string $name): bool
    {
        $root = $this->path();
        $name = trim($name);
        $directory = Path::join($root, $name);
        $zip = Path::join($root, $name . '.zip');

        if (!Path::within($root, $directory) || !Path::within($root, $zip)) {
            throw new BackupException('备份名称不合法：' . $name);
        }

        $deleted = false;

        if (file_exists($directory)) {
            Path::remove($directory);
            $deleted = true;
        }

        if (is_file($zip)) {
            @unlink($zip);
            $deleted = true;
        }

        if (!$deleted) {
            throw new BackupException('备份不存在：' . $name);
        }

        return true;
    }

    /**
     * 将已有备份目录压缩为 zip。
     */
    public function pack(string $name): string
    {
        $root = $this->path();
        $directory = Path::join($root, $name);

        if (!Path::within($root, $directory) || !is_dir($directory)) {
            throw new BackupException('备份目录不存在：' . $name);
        }

        return Archive::pack($directory, Path::join($root, $name . '.zip'), (int) $this->config['zip_level']);
    }

    /**
     * 清理过期备份。
     *
     * @return array<int, string>
     */
    public function clean(int $days = 0, bool $dryRun = false): array
    {
        $days = $days > 0 ? $days : (int) $this->config['retain_days'];

        if ($days <= 0) {
            throw new BackupException('请指定要清理的天数，例如 7。');
        }

        $cutoff = time() - $days * 86400;
        $removed = [];

        foreach ($this->backups() as $entry) {
            if ((int) $entry['timestamp'] >= $cutoff) {
                continue;
            }

            $removed[] = $entry['name'];

            if (!$dryRun) {
                $this->delete($entry['name']);
            }
        }

        return $removed;
    }

    /**
     * 返回当前数据库的表名。
     *
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function tables(array $options = []): array
    {
        $database = $this->database($this->options($options));
        $name = (string) ($options['database'] ?? $this->config['database']);

        if ($name !== '') {
            $database->switchDatabase(Identifier::assertDatabase($name));
        }

        return array_map(
            static fn(array $table): string => $table['name'],
            Schema::tables($database, (bool) ($options['include_views'] ?? $this->config['include_views']))
        );
    }

    /**
     * 获取表维护实例。
     *
     * @param array<string, mixed> $options
     */
    public function maintenance(array $options = []): Maintenance
    {
        $options = $this->options($options);
        $database = $this->database($options);
        $name = (string) ($options['database'] ?? '');

        return new Maintenance($database, $name !== '' ? $name : null);
    }

    /**
     * 返回当前连接可见的数据库列表。
     *
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function databases(array $options = []): array
    {
        $database = $this->database($this->options($options));
        $names = [];

        foreach ($database->query('SHOW DATABASES') as $row) {
            $values = array_values($row);

            if (isset($values[0])) {
                $names[] = (string) $values[0];
            }
        }

        return $names;
    }

    /**
     * 创建数据库。
     *
     * @param array<string, mixed> $options
     */
    public function createDatabase(string $name, string $charset = '', array $options = []): bool
    {
        $name = Identifier::assertDatabase($name);
        $charset = trim($charset);
        $sql = 'CREATE DATABASE IF NOT EXISTS ' . Identifier::quote($name);

        if ($charset !== '') {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
                throw new BackupException('数据库字符集不合法：' . $charset);
            }

            $sql .= ' DEFAULT CHARACTER SET ' . $charset;
        }

        $this->database($this->options($options))->execute($sql);

        return true;
    }

    /**
     * 删除数据库。该操作不可恢复，请确认数据库名后再调用。
     *
     * @param array<string, mixed> $options
     */
    public function dropDatabase(string $name, array $options = []): bool
    {
        $name = Identifier::assertDatabase($name);

        $this->database($this->options($options))->execute('DROP DATABASE IF EXISTS ' . Identifier::quote($name));

        return true;
    }

    /**
     * 返回指定表的字段信息。
     *
     * @param array<string, mixed> $options
     * @return array<int, array{name: string, type: string, key: string, extra: string}>
     */
    public function columns(string $table, array $options = []): array
    {
        return $this->maintenance($options)->columns($table);
    }

    /**
     * 优化表。可传单个表名或表名数组。
     *
     * @param array<int, string>|string $tables
     * @param array<string, mixed> $options
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function optimize($tables, array $options = []): array
    {
        return $this->maintenance($options)->action('optimize', Identifier::list($tables));
    }

    /**
     * 修复表。可传单个表名或表名数组。
     *
     * @param array<int, string>|string $tables
     * @param array<string, mixed> $options
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function repair($tables, array $options = []): array
    {
        return $this->maintenance($options)->action('repair', Identifier::list($tables));
    }

    /**
     * 检查表。可传单个表名或表名数组。
     *
     * @param array<int, string>|string $tables
     * @param array<string, mixed> $options
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function check($tables, array $options = []): array
    {
        return $this->maintenance($options)->action('check', Identifier::list($tables));
    }

    /**
     * 清空表。可传单个表名或表名数组。
     *
     * @param array<int, string>|string $tables
     * @param array<string, mixed> $options
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function truncate($tables, array $options = []): array
    {
        return $this->maintenance($options)->action('truncate', Identifier::list($tables));
    }

    /**
     * 删除表。可传单个表名或表名数组。
     *
     * @param array<int, string>|string $tables
     * @param array<string, mixed> $options
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function dropTable($tables, array $options = []): array
    {
        return $this->maintenance($options)->action('drop', Identifier::list($tables));
    }

    /**
     * 批量替换表前缀。
     *
     * @param array<int, string>|string $tables
     * @param array<string, mixed> $options
     * @return array<int, array{action: string, table: string, affected: int}>
     */
    public function renameTablePrefix($tables, string $oldPrefix, string $newPrefix, array $options = []): array
    {
        return $this->maintenance($options)->action('rename-prefix', Identifier::list($tables), [
            'old_prefix' => $oldPrefix,
            'new_prefix' => $newPrefix,
        ]);
    }

    /**
     * 执行一条 SQL。
     *
     * @param array<string, mixed> $options
     */
    public function executeSql(string $sql, array $options = []): int
    {
        return $this->maintenance($options)->execute($sql);
    }

    /**
     * 执行 SQL 文件。
     *
     * @param array<string, mixed> $options
     */
    public function executeSqlFile(string $file, array $options = []): int
    {
        return $this->maintenance($options)->executeFile($file);
    }

    /**
     * 读取配置。
     *
     * @param mixed $default
     * @return mixed
     */
    public function config(?string $name = null, $default = null)
    {
        if ($name === null) {
            return $this->config;
        }

        return $this->config[$name] ?? $default;
    }

    /**
     * 备份根目录。
     */
    public function path(): string
    {
        return Path::absolute((string) $this->config['path']);
    }

    /**
     * 应用配置中的 PHP 执行超时。
     */
    protected function applyTimeLimit(): void
    {
        $limit = (int) ($this->config['time_limit'] ?? 0);

        if ($limit > 0 && function_exists('set_time_limit')) {
            @set_time_limit($limit);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function options(array $options): array
    {
        $options = array_replace($this->config, $options);
        $options['tables'] = Identifier::list($options['tables'] ?? []);
        $options['exclude'] = Identifier::list($options['exclude'] ?? []);
        $options['ignore_auto_increment'] = Identifier::list($options['ignore_auto_increment'] ?? []);
        $options['connection'] = (string) ($options['connection'] ?? '');
        $options['database'] = (string) ($options['database'] ?? '');

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function database(array $options): Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        $connection = (string) ($options['connection'] ?? '');

        return new ThinkDatabase($connection !== '' ? $connection : null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function entry(string $name, string $directory): array
    {
        $manifest = null;

        try {
            $manifest = Manifest::load($directory);
        } catch (Throwable $e) {
            $manifest = null;
        }

        $zip = Path::join($this->path(), $name . '.zip');

        return $this->makeEntry($name, $directory, is_file($zip) ? $zip : null, $manifest);
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return array<string, mixed>
     */
    protected function makeEntry(string $name, ?string $directory, ?string $zip, ?array $manifest): array
    {
        $path = $directory ?? (string) $zip;
        $size = $directory !== null ? Path::size($directory) : (int) @filesize((string) $zip);
        $tables = (array) ($manifest['tables'] ?? []);
        $rows = 0;

        foreach ($tables as $table) {
            $rows += (int) ($table['rows'] ?? 0);
        }

        $timestamp = @filemtime($path);

        if ($manifest !== null && !empty($manifest['created_at'])) {
            $parsed = strtotime((string) $manifest['created_at']);
            $timestamp = $parsed !== false ? $parsed : $timestamp;
        }

        return [
            'name' => $name,
            'path' => $path,
            'zip' => $zip,
            'database' => (string) ($manifest['database'] ?? ''),
            'created_at' => (string) ($manifest['created_at'] ?? date('Y-m-d H:i:s', $timestamp ?: time())),
            'tables' => count($tables),
            'rows' => $rows,
            'size' => $size,
            'size_text' => self::sizeText($size),
            'timestamp' => $timestamp ?: 0,
            'manifest' => $manifest,
        ];
    }

    /**
     * 从 zip 中读取备份清单。
     *
     * @return array<string, mixed>|null
     */
    protected function zipManifest(string $zipFile): ?array
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            return null;
        }

        try {
            $content = $zip->getFromName(Manifest::FILE);

            if ($content === false) {
                return null;
            }

            $data = json_decode($content, true);

            return is_array($data) ? Manifest::make($data) : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * 人类可读的文件大小。
     */
    public static function sizeText(int $size): string
    {
        if ($size < 1024) {
            return $size . ' B';
        }

        if ($size < 1048576) {
            return round($size / 1024, 2) . ' KB';
        }

        if ($size < 1073741824) {
            return round($size / 1048576, 2) . ' MB';
        }

        return round($size / 1073741824, 2) . ' GB';
    }
}