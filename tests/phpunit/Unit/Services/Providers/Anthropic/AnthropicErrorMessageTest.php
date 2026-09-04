<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\Anthropic;

use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicErrorMessage;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Anthropic 固有のエラー type から利用者向け日本語メッセージへの変換を固定する。
 */
final class AnthropicErrorMessageTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function errorTypes(): array
    {
        return [
            'authentication_error' => ['authentication_error', 'API キー'],
            'permission_error' => ['permission_error', '権限'],
            'not_found_error' => ['not_found_error', 'モデル'],
            'rate_limit_error' => ['rate_limit_error', '集中'],
            'overloaded_error' => ['overloaded_error', '混雑'],
            'api_error' => ['api_error', '一時的'],
            'invalid_request_error' => ['invalid_request_error', '不正'],
        ];
    }

    #[Test]
    #[DataProvider('errorTypes')]
    #[TestDox('エラー type ごとに原因が分かる日本語メッセージを返す')]
    public function mapsKnownErrorTypes(string $type, string $expectedFragment): void
    {
        $error = json_decode(json_encode(['type' => $type, 'message' => 'raw message']));

        self::assertStringContainsString($expectedFragment, AnthropicErrorMessage::fromError($error));
    }

    #[Test]
    #[TestDox('クレジット残高不足（invalid_request_error で届く）は残高の案内を返す')]
    public function detectsLowCreditBalance(): void
    {
        // 実際の API 応答（type は invalid_request_error）を再現する。
        $error = json_decode(json_encode([
            'type' => 'invalid_request_error',
            'message' => 'Your credit balance is too low to access the Anthropic API. '
                . 'Please go to Plans & Billing to upgrade or purchase credits.',
        ]));

        $message = AnthropicErrorMessage::fromError($error);

        self::assertStringContainsString('クレジット残高', $message);
        self::assertStringNotContainsString('リクエストが不正', $message);
    }

    #[Test]
    #[TestDox('未知の type・不正な形は汎用メッセージへフォールバックする')]
    public function fallsBackForUnknownShapes(): void
    {
        $fallback = 'AI からの応答取得に失敗しました。設定や Anthropic の状態をご確認ください。';

        self::assertSame($fallback, AnthropicErrorMessage::fromError(json_decode('{"type":"future_error"}')));
        self::assertSame($fallback, AnthropicErrorMessage::fromError(null));
        self::assertSame($fallback, AnthropicErrorMessage::fromError('string'));
        self::assertSame($fallback, AnthropicErrorMessage::fromError(json_decode('{}')));
    }
}
