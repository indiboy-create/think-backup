<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Support;

use Generator;
use Youvision\ThinkBackup\Exception\BackupException;

/**
 * SQL 语句拆分器。
 *
 * 用于恢复 .sql 备份文件。支持：
 *  - 单引号、双引号、反引号及反斜杠转义；
 *  - 单引号/双引号/反引号成对转义（''、""、``）；
 *  - --、# 行注释与块注释；
 *  - DELIMITER 指令；
 *  - 分块读取大文件，避免一次性载入内存。
 *
 * 注意：备份文件中的普通块注释会被保留，MySQL 版本注释 /*! ... *\/ 同样保留，
 * 以保证 SET 语句仍然生效。
 */
final class SqlSplitter
{
    protected string $buffer = '';

    protected string $current = '';

    protected string $state = 'normal';

    protected string $delimiter = ';';

    protected bool $atLineStart = true;

    protected bool $escaped = false;

    /**
     * 拆分内存中的 SQL 字符串。
     *
     * @return array<int, string>
     */
    public function split(string $sql): array
    {
        $splitter = new self();
        $statements = [];

        foreach ($splitter->feed($sql, true) as $statement) {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * 分块读取 SQL 文件并逐条回调，返回执行的语句数量。
     *
     * @param callable(string): void $callback
     */
    public function splitFile(string $file, callable $callback): int
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new BackupException('无法读取 SQL 文件：' . $file);
        }

        $count = 0;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);

                if ($chunk === false) {
                    break;
                }

                foreach ($this->feed($chunk, false) as $statement) {
                    $callback($statement);
                    $count++;
                }
            }

            foreach ($this->feed('', true) as $statement) {
                $callback($statement);
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * 向拆分器送入一段文本，返回已完整的语句。
     *
     * @return Generator<int, string>
     */
    protected function feed(string $chunk, bool $final): Generator
    {
        $this->buffer .= $chunk;
        $length = strlen($this->buffer);
        $index = 0;

        while ($index < $length) {
            $char = $this->buffer[$index];

            if ($this->state === 'line') {
                if ($char === "\n") {
                    $this->state = 'normal';
                    $this->atLineStart = true;
                }

                $index++;
                continue;
            }

            if ($this->state === 'block') {
                if ($char === '*' && $index + 1 < $length && $this->buffer[$index + 1] === '/') {
                    $this->current .= '*/';
                    $index += 2;
                    $this->state = 'normal';
                    continue;
                }

                if ($char === '*' && $index + 1 >= $length && !$final) {
                    break;
                }

                $this->current .= $char;
                $index++;
                continue;
            }

            if ($this->state === 'single' || $this->state === 'double' || $this->state === 'backtick') {
                $this->current .= $char;
                $index++;

                if ($this->escaped) {
                    $this->escaped = false;
                    continue;
                }

                if ($this->state !== 'backtick' && $char === '\\') {
                    $this->escaped = true;
                    continue;
                }

                $closing = $this->state === 'single' ? "'" : ($this->state === 'double' ? '"' : '`');

                if ($char === $closing) {
                    $this->state = 'normal';
                    $this->atLineStart = false;
                }

                continue;
            }

            // 分块读取时保留可能构成 --、/* 或 DELIMITER 的末尾字符，
            // 避免注释与指令被截断后误判为普通 SQL。
            if (!$final && $index === $length - 1 && ($char === '-' || $char === '/')) {
                break;
            }

            if (
                $this->atLineStart
                && trim($this->current) === ''
                && !$final
                && $length - $index < 9
                && strncasecmp(substr($this->buffer, $index), substr('DELIMITER', 0, $length - $index), $length - $index) === 0
            ) {
                break;
            }

            // DELIMITER 指令只在语句开头、行首生效。
            if ($this->atLineStart && trim($this->current) === '' && strncasecmp(substr($this->buffer, $index, 9), 'DELIMITER', 9) === 0) {
                $lineEnd = strpos($this->buffer, "\n", $index);

                if ($lineEnd === false && !$final) {
                    break;
                }

                $line = $lineEnd === false
                    ? substr($this->buffer, $index)
                    : substr($this->buffer, $index, $lineEnd - $index);

                if (preg_match('/^DELIMITER\s+(\S+)/i', trim($line), $match)) {
                    $this->delimiter = $match[1];
                    $this->current = '';
                    $this->atLineStart = true;
                    $index = $lineEnd === false ? $length : $lineEnd + 1;
                    continue;
                }
            }

            if ($char === "'") {
                $this->state = 'single';
                $this->current .= $char;
                $this->atLineStart = false;
                $index++;
                continue;
            }

            if ($char === '"') {
                $this->state = 'double';
                $this->current .= $char;
                $this->atLineStart = false;
                $index++;
                continue;
            }

            if ($char === '`') {
                $this->state = 'backtick';
                $this->current .= $char;
                $this->atLineStart = false;
                $index++;
                continue;
            }

            if ($char === '#') {
                $this->state = 'line';
                $index++;
                continue;
            }

            if ($char === '-' && $index + 1 < $length && $this->buffer[$index + 1] === '-') {
                if ($index + 2 >= $length && !$final) {
                    break;
                }

                $next = $index + 2 < $length ? $this->buffer[$index + 2] : ' ';

                if ($next === ' ' || $next === "\t" || $next === "\r" || $next === "\n") {
                    $this->state = 'line';
                    $index += 2;
                    continue;
                }
            }

            if ($char === '/' && $index + 1 < $length && $this->buffer[$index + 1] === '*') {
                $this->state = 'block';
                $this->current .= '/*';
                $this->atLineStart = false;
                $index += 2;
                continue;
            }

            if ($this->delimiter === ';' && $char === ';') {
                $statement = trim($this->current);
                $this->current = '';
                $this->atLineStart = false;
                $index++;

                if ($this->meaningful($statement)) {
                    yield $statement;
                }

                continue;
            }

            if ($this->delimiter !== ';') {
                $this->current .= $char;
                $index++;
                $this->atLineStart = false;

                if (str_ends_with($this->current, $this->delimiter)) {
                    $statement = trim(substr($this->current, 0, -strlen($this->delimiter)));
                    $this->current = '';

                    if ($this->meaningful($statement)) {
                        yield $statement;
                    }
                }

                continue;
            }

            if ($char === "\n") {
                $this->atLineStart = true;
            } elseif ($char !== "\r" && $char !== "\t" && $char !== ' ') {
                $this->atLineStart = false;
            }

            if (trim($this->current) !== '' || ($char !== ' ' && $char !== "\t" && $char !== "\r" && $char !== "\n")) {
                $this->current .= $char;
            }

            $index++;
        }

        $this->buffer = $index >= $length ? '' : substr($this->buffer, $index);

        if ($final) {
            $this->state = 'normal';
            $this->escaped = false;
            $statement = trim($this->current);
            $this->current = '';

            if ($this->meaningful($statement)) {
                if ($this->delimiter !== ';' && str_ends_with($statement, $this->delimiter)) {
                    $statement = trim(substr($statement, 0, -strlen($this->delimiter)));
                }

                if ($statement !== '') {
                    yield $statement;
                }
            }
        }
    }

    /**
     * 过滤只包含注释或空白的内容。
     */
    protected function meaningful(string $sql): bool
    {
        if ($sql === '') {
            return false;
        }

        $clean = preg_replace('#/\*(?!!)[\s\S]*?\*/#', '', $sql) ?? $sql;
        $clean = preg_replace('/(?:^|\n)\s*(?:--[^\n]*|#[^\n]*)/', '', $clean) ?? $clean;

        return trim($clean) !== '';
    }
}