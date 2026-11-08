<?php
declare(strict_types=1);

use Youvision\ThinkBackup\Backup;

if (!function_exists('think_backup')) {
    /**
     * 获取备份管理器。
     *
     * 服务发现未执行时也会创建独立实例，便于在临时脚本中直接使用。
     */
    function think_backup(): Backup
    {
        try {
            $instance = app('backup');

            if ($instance instanceof Backup) {
                return $instance;
            }
        } catch (\Throwable $e) {
        }

        $config = [];

        if (function_exists('config')) {
            try {
                $config = (array) config('backup', []);
            } catch (\Throwable $e) {
                $config = [];
            }
        }

        return new Backup($config);
    }
}