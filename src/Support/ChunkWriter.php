<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use Youvision\ThinkBackup\Exception\BackupException;

/**
 * 分卷文件写入器。
 *
 * 每个表可以产生多个文件：table_1.sql、table_2.sql ……。
 * 文件头写入 SET NAMES 与 SET FOREIGN_KEY_CHECKS，保证单独导入某一卷
 * 时也能正确识别字符集并跳过外键约束。
 */
final class ChunkWriter
{
    protected string $directory;

    protected string $table;

    /**
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * @var resource|null
     */
    protected $handle = null;

    protected int $index = 0;

    protected int $bytes = 0;

    protected int $records = 0;

    /**
     * @var array<int, string>
     */
    protected array $files = [];

    protected string $charset;

    protected string $createdAt;

    /**
     * @var callable|null
     */
    protected $onOpen;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $directory, string $table, array $options = [], ?callable $onOpen = null)
    {
        $this->directory = $directory;
        $this->table = $table;
        $this->options = $options;
        $this->charset = self::charset((string) ($options['charset'] ?? ''));
        $this->createdAt = date('Y-m-d H:i:s');
        $this->onOpen = $onOpen;
    }

    /**
     * 写入一条 SQL。$record 为 true 时计入记录数并参与按记录分卷。
     */
    public function write(string $sql, bool $record = false): void
    {
        if ($this->handle === null) {
            $this->open();
        }

        if ($record && $this->needRotate($sql)) {
            $this->closeFile();
            $this->open();
        }

        $content = $sql . "\n";

        if (@fwrite($this->handle, $content) === false) {
            throw new BackupException('写入备份文件失败：' . $this->currentPath());
        }

        $this->bytes += strlen($content);

        if ($record) {
            $this->records++;
        }
    }

    /**
     * 关闭当前文件。若从未写入过内容，也会生成一个空文件，便于恢复时识别空表。
     *
     * @return array<int, string>
     */
    public function close(): array
    {
        if ($this->handle === null) {
            $this->open();
        }

        $this->closeFile();

        return $this->files;
    }

    /**
     * 已生成的文件名。
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * 当前卷已写入记录数。
     */
    public function records(): int
    {
        return $this->records;
    }

    protected function open(): void
    {
        $this->index++;
        $file = Identifier::fileName($this->table) . '_' . $this->index . '.sql';
        $path = Path::join($this->directory, $file);
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new BackupException('无法创建备份文件：' . $path);
        }

        $this->handle = $handle;
        $this->bytes = 0;
        $this->files[] = $file;

        $header = '-- think-backup database dump' . "\n"
            . '-- Table: ' . Identifier::quote($this->table) . "\n"
            . '-- Part: ' . $this->index . "\n"
            . '-- Created: ' . $this->createdAt . "\n"
            . 'SET NAMES ' . "'" . Identifier::literal($this->charset) . "'" . ";\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n";

        fwrite($handle, $header);
        $this->bytes += strlen($header);

        if ($this->onOpen !== null) {
            ($this->onOpen)($file, $this->index);
        }
    }

    protected function closeFile(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->records = 0;
    }

    protected function needRotate(string $sql): bool
    {
        if ($this->records === 0) {
            return false;
        }

        $mode = (string) ($this->options['mode'] ?? 'size');

        if ($mode === 'line') {
            $line = (int) ($this->options['line'] ?? 0);

            return $line > 0 && $this->records >= $line;
        }

        $size = (int) ($this->options['size'] ?? 0);

        if ($size <= 0) {
            return false;
        }

        return $this->bytes + strlen($sql) + 2 > $size * 1024;
    }

    protected function currentPath(): string
    {
        return Path::join($this->directory, Identifier::fileName($this->table) . '_' . max(1, $this->index) . '.sql');
    }

    protected static function charset(string $charset): string
    {
        $charset = trim($charset);

        return preg_match('/^[A-Za-z0-9_]+$/', $charset) ? $charset : 'utf8mb4';
    }
}