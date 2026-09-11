<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Acms\Services\Facades\Database as DB;
use Acms\Services\Facades\RateLimiter;

/**
 * a-blog cms 本体の DB ベース制限を使い、ブログ・ユーザー単位で AI リクエストを抑制する。
 */
final class AiRequestRateLimiter
{
    private const DEFAULT_WINDOW_MINUTES = 1;
    private const DEFAULT_MAX_REQUESTS = 20;
    private const DEFAULT_LOCK_MINUTES = 5;
    private const ADVISORY_LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly int $windowMinutes,
        private readonly int $maxRequests,
        private readonly int $lockMinutes,
    ) {
        if ($windowMinutes < 1 || $maxRequests < 0 || $lockMinutes < 1) {
            throw new \InvalidArgumentException('Invalid AI request rate limit.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            self::positiveConfig('ai_rate_limit_window_minutes', self::DEFAULT_WINDOW_MINUTES),
            self::nonNegativeConfig('ai_rate_limit_requests', self::DEFAULT_MAX_REQUESTS),
            self::positiveConfig('ai_rate_limit_lock_minutes', self::DEFAULT_LOCK_MINUTES),
        );
    }

    /**
     * 今回のリクエストが上限内なら試行を記録して true、超過中なら false を返す。
     * 0 requests は管理者が明示的に制限を無効化した状態として扱う。
     */
    public function consume(int $blogId, int $userId): bool
    {
        if ($this->maxRequests === 0) {
            return true;
        }

        $lockKey = "acms-ai:{$blogId}:{$userId}";
        $advisoryLock = self::advisoryLockName($lockKey);
        if (!$this->acquireLock($advisoryLock)) {
            return false;
        }

        try {
            if (
                !RateLimiter::validateLockPost(
                    $lockKey,
                    $this->windowMinutes,
                    $this->maxRequests,
                    $this->lockMinutes,
                    false,
                )
            ) {
                return false;
            }

            RateLimiter::logLockPost($lockKey);
            return true;
        } finally {
            $this->releaseLock($advisoryLock);
        }
    }

    /**
     * MySQLサーバを共有する複数CMSや、64文字のGET_LOCK上限を考慮したロック名を返す。
     */
    private static function advisoryLockName(string $lockKey): string
    {
        $database = defined('DB_NAME') ? constant('DB_NAME') : '';
        $prefix = defined('DB_PREFIX') ? constant('DB_PREFIX') : '';

        return 'acms_ai_' . sha1($database . '|' . $prefix . '|' . $lockKey);
    }

    private function acquireLock(string $name): bool
    {
        try {
            $acquired = DB::query(
                [
                    'sql' => 'SELECT GET_LOCK(?, ?)',
                    'params' => [$name, self::ADVISORY_LOCK_TIMEOUT_SECONDS],
                ],
                'one',
            );

            return (string) $acquired === '1';
        } catch (\Throwable) {
            // 制限処理を直列化できない場合は、有料API呼び出しを安全側に拒否する。
            return false;
        }
    }

    private function releaseLock(string $name): void
    {
        try {
            DB::query(
                ['sql' => 'SELECT RELEASE_LOCK(?)', 'params' => [$name]],
                'one',
            );
        } catch (\Throwable) {
            // 接続終了時にも解放されるため、元の判定結果を維持する。
        }
    }

    private static function positiveConfig(string $key, int $default): int
    {
        $value = config($key, $default);
        if (!is_numeric($value) || (int) $value < 1) {
            return $default;
        }

        return (int) $value;
    }

    private static function nonNegativeConfig(string $key, int $default): int
    {
        $value = config($key, $default);
        if (!is_numeric($value) || (int) $value < 0) {
            return $default;
        }

        return (int) $value;
    }
}
