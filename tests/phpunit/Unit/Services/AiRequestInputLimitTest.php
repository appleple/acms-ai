<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\AiRequestInputLimit;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class AiRequestInputLimitTest extends TestCase
{
    #[Test]
    #[TestDox('複数入力の合計が上限以下なら許可する')]
    public function acceptsCombinedValuesWithinLimit(): void
    {
        $limit = new AiRequestInputLimit(6);

        self::assertTrue($limit->accepts('abc', 'def'));
        self::assertFalse($limit->accepts('abc', 'defg'));
    }

    #[Test]
    #[TestDox('文字数ではなく UTF-8 のバイト数で判定する')]
    public function countsUtf8Bytes(): void
    {
        $limit = new AiRequestInputLimit(6);

        self::assertTrue($limit->accepts('日本'));
        self::assertFalse($limit->accepts('日本語'));
    }

    #[Test]
    #[TestDox('1 未満の上限を拒否する')]
    public function rejectsInvalidLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AiRequestInputLimit(0);
    }
}
