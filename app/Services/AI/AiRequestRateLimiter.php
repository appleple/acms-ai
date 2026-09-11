<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Acms\Services\Facades\RateLimiter;

/**
 * a-blog cms 本体の DB ベース制限を使い、ブログ・ユーザー単位で AI リクエストを抑制する。
 */
final class AiRequestRateLimiter
{
    private const DEFAULT_WINDOW_MINUTES = 1;
    private const DEFAULT_MAX_REQUESTS = 20;
    private const DEFAULT_LOCK_MINUTES = 5;

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
