<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Youvision\ThinkBackup\Exception\BackupException;

/**
 * 路径处理与目录操作。
 *
 * 备份目录中的名称来自调用参数或备份清单，必须保证最终路径始终位于
 * 备份根目录内，避免 ../、绝对路径或 Windows 盘符造成越权访问。
 */
final class Path
{
    /**
     * 判断是否为绝对路径。
     */
    public static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $path);
    }

    /**
     * 转为绝对路径。相对路径基于项目根目录或当前工作目录解析。
     */
    public static function absolute(string $path, ?string $base = null): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = '.';
        }

        if (self::isAbsolute($path)) {
            return self::normalize($path);
        }

        $base = $base ?? self::basePath();

        return self::normalize(rtrim($base, '/\\') . '/' . $path);
    }

    /**
     * 拼接路径。
     */
    public static function join(string ...$parts): string
    {
        $path = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $path = $path === '' ? $part : rtrim($path, '/\\') . '/' . ltrim($part, '/\\');
        }

        return self::normalize($path);
    }

    /**
     * 规范化路径分隔符并解析 . 与 ..，不要求路径已存在。
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $drive = '';
        $absolute = false;

        if (preg_match('#^([A-Za-z]):/#', $path, $match)) {
            $drive = strtoupper($match[1]) . ':/';
            $path = substr($path, 3);
            $absolute = true;
        } elseif (str_starts_with($path, '//')) {
            $path = ltrim($path, '/');
            $absolute = true;
        } elseif (str_starts_with($path, '/')) {
            $path = ltrim($path, '/');
            $absolute = true;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (!$absolute) {
                    $segments[] = '..';
                }

                continue;
            }

            $segments[] = $segment;
        }

        $result = implode('/', $segments);

        if ($drive !== '') {
            return $drive . $result;
        }

        if ($absolute) {
            return '/' . $result;
        }

        return $result === '' ? '.' : $result;
    }

    /**
     * 判断路径是否位于指定目录内（包含目录本身）。
     */
    public static function within(string $root, string $path): bool
    {
        $root = rtrim(self::normalize($root), '/');
        $path = self::normalize($path);

        if ($root === '' || $path === '') {
            return false;
        }

        $compare = static function (string $value): string {
            return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
        };

        $root = $compare($root);
        $path = $compare($path);

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * 返回相对路径。
     */
    public static function relative(string $root, string $path): string
    {
        $root = rtrim(self::normalize($root), '/') . '/';
        $path = self::normalize($path);

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * 创建目录，已存在时返回 true。
     */
    public static function ensureDirectory(string $directory, int $permissions = 0755): bool
    {
        $directory = self::normalize($directory);

        if (is_dir($directory)) {
            return true;
        }

        if (!@mkdir($directory, $permissions, true) && !is_dir($directory)) {
            throw new BackupException('无法创建目录：' . $directory);
        }

        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($directory, $permissions);
        }

        return true;
    }

    /**
     * 递归删除目录或删除单个文件。
     */
    public static function remove(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }

        if (!is_dir($path)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        return @rmdir($path);
    }

    /**
     * 目录大小，单位字节。
     */
    public static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) @filesize($path);
        }

        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += (int) $item->getSize();
            }
        }

        return $size;
    }

    /**
     * 生成随机后缀，用于默认备份目录名和临时目录。
     */
    public static function randomSuffix(int $length = 6): string
    {
        try {
            $value = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $value = md5(uniqid('', true));
        }

        return strtolower(substr($value, 0, max(1, $length)));
    }

    /**
     * 项目根目录。
     */
    protected static function basePath(): string
    {
        if (function_exists('app')) {
            try {
                $root = app()->getRootPath();

                if (is_string($root) && $root !== '') {
                    return self::normalize($root);
                }
            } catch (\Throwable $e) {
            }
        }

        $cwd = getcwd();

        return self::normalize($cwd === false ? '.' : $cwd);
    }
}