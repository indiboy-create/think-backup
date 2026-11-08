<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup;

use think\Service as ThinkService;

/**
 * ThinkPHP 服务注册。
 *
 * composer.json 已通过 extra.think.services 声明本服务，安装后执行
 * php think service:discover 即可自动发现。服务负责合并配置，并把
 * 备份管理器注册到容器，供依赖注入、门面与 think_backup() 助手使用。
 */
class Service extends ThinkService
{
    public function register(): void
    {
        $config = $this->mergeConfig();

        $this->app->config->set($config, 'backup');

        $manager = new Backup($config);

        $this->app->instance('backup', $manager);
        $this->app->instance(Backup::class, $manager);
    }

    /**
     * 合并包内默认配置与项目 config/backup.php，项目配置优先。
     *
     * @return array<string, mixed>
     */
    protected function mergeConfig(): array
    {
        $default = Backup::defaults();
        $current = $this->app->config->get('backup', []);

        if (!is_array($current)) {
            $current = [];
        }

        return array_replace_recursive($default, $current);
    }
}