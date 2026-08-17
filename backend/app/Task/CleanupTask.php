<?php

declare(strict_types=1);

namespace App\Task;

use Hyperf\DbConnection\Db;
use Psr\Log\LoggerInterface;

/**
 * 清理任务（docs/03 §5）：验证码表物理删除超 TTL + 宽限期行（一次性凭证不留存）。
 * 归档任务全部落地实现（规范 §2.7「不允许只写注释不实现」），分批 LIMIT 分片。
 */
class CleanupTask
{
    private const BATCH = 500;

    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * 每日执行：删除过期验证码（TTL 5 分钟 + 24 小时宽限期）。
     */
    public function cleanVerificationCodes(): void
    {
        $deadline = time() - 300 - 86400; // expires_at 超过宽限期
        $deleted = 0;
        while (true) {
            $ids = Db::table('verification_codes')
                ->where('expires_at', '<', $deadline)
                ->limit(self::BATCH)
                ->pluck('id')
                ->toArray();
            if ($ids === []) {
                break;
            }
            $deleted += Db::table('verification_codes')->whereIn('id', $ids)->delete();
        }
        if ($deleted > 0) {
            $this->logger->info('cleanup_verification_codes', ['deleted' => $deleted]);
        }
    }
}
