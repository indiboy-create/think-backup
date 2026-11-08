<?php
declare(strict_types=1);

namespace Youvision\ThinkBackup\Exception;

use RuntimeException;

/**
 * 备份、恢复或维护过程中的业务异常。
 */
class BackupException extends RuntimeException
{
}