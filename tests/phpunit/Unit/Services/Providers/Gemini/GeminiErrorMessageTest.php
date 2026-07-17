<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\Gemini;

use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiErrorMessage;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Gemini 固有のエラー status から利用者向け日本語メッセージへの変換を固定する。
 */
final class GeminiErrorMessageTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function errorStatuses(): array
    {
        return [
            'UNAUTHENTICATED' => ['UNAUTHENTICATED', 'API キー'],
            'PERMISSION_DENIED' => ['PERMISSION_DENIED', '権限'],
            'NOT_FOUND' => ['NOT_FOUND', 'モデル'],
            'RESOURCE_EXHAUSTED' => ['RESOURCE_EXHAUSTED', 'クォータ'],
            'INVALID_ARGUMENT' => ['INVALID_ARGUMENT', '不正'],
            'UNAVAILABLE' => ['UNAVAILABLE', '混雑'],
            'INTERNAL' => ['INTERNAL', '一時的'],
        ];
    }

    #[Test]
    #[DataProvider('errorStatuses')]
    #[TestDox('エラー status ごとに原因が分かる日本語メッセージを返す')]
    public function mapsKnownErrorStatuses(string $status, string $expectedFragment): void
    {
        $error = json_decode(json_encode(['code' => 400, 'message' => 'raw', 'status' => $status]));

        self::assertStringContainsString($expectedFragment, GeminiErrorMessage::fromError($error));
    }

    #[Test]
    #[TestDox('未知の status・不正な形は汎用メッセージへフォールバックする')]
    public function fallsBackForUnknownShapes(): void
    {
        $fallback = 'AI からの応答取得に失敗しました。設定や Gemini の状態をご確認ください。';

        self::assertSame($fallback, GeminiErrorMessage::fromError(json_decode('{"status":"FUTURE_STATUS"}')));
        self::assertSame($fallback, GeminiErrorMessage::fromError(null));
        self::assertSame($fallback, GeminiErrorMessage::fromError('string'));
        self::assertSame($fallback, GeminiErrorMessage::fromError(json_decode('{}')));
    }
}
