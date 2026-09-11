<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Integration\Services;

use Acms\Plugins\AI\Services\AI\AiRequestRateLimiter;
use Acms\TestingFramework\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class AiRequestRateLimiterTest extends DatabaseTestCase
{
    private const TEST_BLOG_ID = 2147483645;
    private const TEST_USER_ID = 2147483644;

    #[Test]
    #[TestDox('ブログ・ユーザー単位の試行回数を超えると拒否する')]
    public function rejectsRequestsOverLimit(): void
    {
        $limiter = new AiRequestRateLimiter(1, 2, 5);

        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertFalse($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
    }

    #[Test]
    #[TestDox('異なるブログまたはユーザーの試行回数を共有しない')]
    public function isolatesCountersByBlogAndUser(): void
    {
        $limiter = new AiRequestRateLimiter(1, 1, 5);

        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertFalse($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID + 1));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID - 1, self::TEST_USER_ID));
    }

    #[Test]
    #[TestDox('上限が 0 なら試行を拒否しない')]
    public function allowsRequestsWhenDisabled(): void
    {
        $limiter = new AiRequestRateLimiter(1, 0, 5);

        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
    }
}
