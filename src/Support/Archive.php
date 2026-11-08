<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Youvision\ThinkBackup\Exception\BackupException;
use ZipArchive;

/**
 * 备份压缩包处理。
 */
final class Archive
{
    /**
     * 将目录打包为 zip。压缩包中的路径相对于目录本身。
     */
    public static function pack(string $directory, string $zipFile, int $level = 6): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new BackupException('PHP 未安装 zip 扩展，无法生成压缩包。');
        }

        if (!is_dir($directory)) {
            throw new BackupException('要压缩的目录不存在：' . $directory);
        }

        Path::ensureDirectory(dirname($zipFile));

        if (is_file($zipFile)) {
            @unlink($zipFile);
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('无法创建压缩包：' . $zipFile);
        }

        $level = max(0, min(9, $level));

        if (method_exists($zip, 'setCompressionLevel')) {
            $zip->setCompressionLevel($level);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', Path::relative($directory, $path));

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($path, $relative);
            }
        }

        if (!$zip->close()) {
            throw new BackupException('压缩包写入失败：' . $zipFile);
        }

        return $zipFile;
    }

    /**
     * 解压 zip 到指定目录，并拒绝路径穿越条目。
     */
    public static function extract(string $zipFile, string $directory): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new BackupException('PHP 未安装 zip 扩展，无法解压备份包。');
        }

        if (!is_file($zipFile)) {
            throw new BackupException('压缩包不存在：' . $zipFile);
        }

        Path::ensureDirectory($directory);

        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            throw new BackupException('无法打开压缩包：' . $zipFile);
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name === false) {
                    continue;
                }

                $name = str_replace('\\', '/', $name);

                if (
                    $name === ''
                    || str_starts_with($name, '/')
                    || preg_match('#^[A-Za-z]:#', $name)
                    || in_array('..', explode('/', $name), true)
                ) {
                    throw new BackupException('压缩包包含不安全的路径：' . $name);
                }

                $target = Path::join($directory, $name);

                if (!Path::within($directory, $target)) {
                    throw new BackupException('压缩包路径超出目标目录：' . $name);
                }

                if (str_ends_with($name, '/')) {
                    Path::ensureDirectory($target);
                    continue;
                }

                Path::ensureDirectory(dirname($target));

                $stream = $zip->getStream($name);

                if ($stream === false) {
                    throw new BackupException('无法读取压缩包条目：' . $name);
                }

                $handle = @fopen($target, 'wb');

                if ($handle === false) {
                    fclose($stream);
                    throw new BackupException('无法写入解压文件：' . $target);
                }

                stream_copy_to_stream($stream, $handle);
                fclose($handle);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * 是否为 zip 文件。
     */
    public static function isZip(string $file): bool
    {
        return is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip';
    }
}