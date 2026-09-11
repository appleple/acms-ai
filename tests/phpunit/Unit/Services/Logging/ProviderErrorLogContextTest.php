<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Logging;

use Acms\Plugins\AI\Services\AI\Logging\ProviderErrorLogContext;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class ProviderErrorLogContextTest extends TestCase
{
    #[Test]
    #[TestDox('外部エラーのメッセージを除き安全な識別子だけを残す')]
    public function keepsIdentifiersWithoutMessage(): void
    {
        $error = (object) [
            'message' => '記事本文やAPIキーを含み得る外部メッセージ sk-secret',
            'type' => 'invalid_request_error',
            'code' => 'invalid_value',
            'param' => 'messages[0].content',
            'status' => 'INVALID_ARGUMENT',
        ];

        $context = ProviderErrorLogContext::from($error);

        self::assertSame([
            'payload_type' => 'stdClass',
            'type' => 'invalid_request_error',
            'code' => 'invalid_value',
            'param' => 'messages[0].content',
            'status' => 'INVALID_ARGUMENT',
        ], $context);
        self::assertArrayNotHasKey('message', $context);
    }

    #[Test]
    #[TestDox('想定外の型や制御文字を含む値をログへ展開しない')]
    public function rejectsRawOrUnsafeValues(): void
    {
        self::assertSame(
            ['payload_type' => 'array'],
            ProviderErrorLogContext::from(['message' => 'secret'])
        );
        self::assertSame(
            ['payload_type' => 'stdClass'],
            ProviderErrorLogContext::from((object) ['type' => "invalid\nforged"])
        );
    }

    #[Test]
    #[TestDox('数値のエラーコードを保持する')]
    public function keepsIntegerCode(): void
    {
        self::assertSame(
            ['payload_type' => 'stdClass', 'code' => 429],
            ProviderErrorLogContext::from((object) ['code' => 429])
        );
    }
}
