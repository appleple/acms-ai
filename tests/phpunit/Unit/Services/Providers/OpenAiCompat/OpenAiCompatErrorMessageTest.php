<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatErrorMessage;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAI 互換エンドポイントのエラー code / type から利用者向け日本語メッセージへの変換を固定する。
 */
final class OpenAiCompatErrorMessageTest extends TestCase
{
    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function errors(): array
    {
        return [
            'insufficient_quota (code)' => [['code' => 'insufficient_quota'], 'クォータ'],
            'invalid_api_key (code)' => [['code' => 'invalid_api_key'], 'API キー'],
            'authentication_error (type)' => [['type' => 'authentication_error'], 'API キー'],
            'model_not_found (code)' => [['code' => 'model_not_found'], 'モデル'],
            'rate_limit (type)' => [['type' => 'rate_limit_error'], '集中'],
            'server_error (type)' => [['type' => 'server_error'], '一時的'],
            'invalid_request (type)' => [['type' => 'invalid_request_error'], '不正'],
        ];
    }

    /**
     * @param array<string, string> $error
     */
    #[Test]
    #[DataProvider('errors')]
    #[TestDox('エラー code / type ごとに原因が分かる日本語メッセージを返す')]
    public function mapsKnownErrors(array $error, string $expectedFragment): void
    {
        $decoded = json_decode(json_encode($error + ['message' => 'raw']));

        self::assertStringContainsString($expectedFragment, OpenAiCompatErrorMessage::fromError($decoded));
    }

    #[Test]
    #[TestDox('未知の code / type・不正な形は汎用メッセージへフォールバックする')]
    public function fallsBackForUnknownShapes(): void
    {
        $fallback = 'AI からの応答取得に失敗しました。設定や接続先サービスの状態をご確認ください。';

        self::assertSame($fallback, OpenAiCompatErrorMessage::fromError(json_decode('{"type":"future_error"}')));
        self::assertSame($fallback, OpenAiCompatErrorMessage::fromError(null));
        self::assertSame($fallback, OpenAiCompatErrorMessage::fromError('string'));
        self::assertSame($fallback, OpenAiCompatErrorMessage::fromError(json_decode('{}')));
    }
}
