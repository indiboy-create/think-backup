<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use Generator;
use Youvision\ThinkBackup\Exception\BackupException;

/**
 * 旧版帝国备份王 PHP 数据文件读取器。
 *
 * 旧版备份文件把 SQL 写在 E_D("...");、E_C("..."); 调用中，
 * 文件还会 require header.php / footer.php 等入口文件。直接 include
 * 会执行备份文件中的代码，存在安全风险。本读取器使用 token_get_all
 * 只解析字符串字面量，不执行任何代码，因此可以安全恢复旧备份。
 */
final class LegacyPhpReader
{
    /**
     * 读取旧版 PHP 备份文件中的 SQL 语句。
     *
     * @return array<int, string>
     */
    public function read(string $file): array
    {
        $statements = [];

        foreach ($this->readFile($file) as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * 逐条读取旧版 PHP 备份文件，返回语句数量。
     *
     * @param callable(string): void $callback
     */
    public function readFile(string $file, ?callable $callback = null): Generator
    {
        $content = @file_get_contents($file);

        if ($content === false) {
            throw new BackupException('无法读取旧版备份文件：' . $file);
        }

        $tokens = token_get_all($content);
        $count = count($tokens);
        $counted = 0;

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = strtoupper($token[1]);

            if (!in_array($name, ['E_D', 'E_C', 'DOSETDBCHAR'], true)) {
                continue;
            }

            $cursor = $index + 1;

            while ($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) {
                $cursor++;
            }

            if ($cursor >= $count || $tokens[$cursor] !== '(') {
                continue;
            }

            $cursor++;

            while ($cursor < $count && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) {
                $cursor++;
            }

            if ($cursor >= $count) {
                continue;
            }

            $argument = $tokens[$cursor];

            if (!is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = $this->decode($argument[1]);

            if ($name === 'DOSETDBCHAR') {
                if ($value !== '') {
                    $statement = 'SET NAMES ' . $this->quote($value);
                    $counted++;
                    yield $statement;

                    if ($callback !== null) {
                        $callback($statement);
                    }
                }

                continue;
            }

            $statement = trim($value);

            if ($statement === '') {
                continue;
            }

            if (!str_ends_with($statement, ';')) {
                $statement .= ';';
            }

            $counted++;
            yield $statement;

            if ($callback !== null) {
                $callback($statement);
            }
        }

        return $counted;
    }

    /**
     * 解析 PHP 字符串字面量，支持单引号与双引号转义。
     */
    protected function decode(string $literal): string
    {
        if ($literal === '') {
            return '';
        }

        $quote = $literal[0];
        $value = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        }

        $map = [
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'v' => "\v",
            'e' => "\e",
            'f' => "\f",
            '\\' => '\\',
            '$' => '$',
            '"' => '"',
        ];

        $result = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];

            if ($char !== '\\' || $index + 1 >= $length) {
                $result .= $char;
                continue;
            }

            $next = $value[$index + 1];

            if (isset($map[$next])) {
                $result .= $map[$next];
                $index++;
                continue;
            }

            if ($next === 'x' && preg_match('/^[0-9A-Fa-f]{1,2}/', substr($value, $index + 2, 2), $match)) {
                $result .= chr((int) hexdec($match[0]));
                $index += 1 + strlen($match[0]);
                continue;
            }

            if (preg_match('/^[0-7]{1,3}/', substr($value, $index + 1, 3), $match)) {
                $result .= chr((int) octdec($match[0]));
                $index += strlen($match[0]);
                continue;
            }

            $result .= '\\' . $next;
            $index++;
        }

        return $result;
    }

    /**
     * SQL 字符串转义。
     */
    protected function quote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}