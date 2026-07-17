<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiErrorMessage;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAI のエラー（code/type）→ 利用者向け日本語メッセージの写像を固定する。
 *
 * 画面・API 応答へ「何をすればよいか」が分かる理由を出すための単一の変換点。既知コードは専用文言へ、
 * 未知・非オブジェクトは汎用文言へフォールバックすることを保証する。
 */
final class OpenAiErrorMessageTest extends TestCase
{
    #[Test]
    #[TestDox('insufficient_quota はクォータ超過の案内を返す')]
    public function quota(): void
    {
        $message = OpenAiErrorMessage::fromError((object) ['code' => 'insufficient_quota']);
        self::assertStringContainsString('利用枠', $message);
    }

    #[Test]
    #[TestDox('model_not_found はモデル設定の案内を返す')]
    public function modelNotFound(): void
    {
        $message = OpenAiErrorMessage::fromError((object) ['code' => 'model_not_found']);
        self::assertStringContainsString('モデル', $message);
    }

    #[Test]
    #[TestDox('authentication_error タイプは API キーの案内を返す')]
    public function authentication(): void
    {
        $message = OpenAiErrorMessage::fromError((object) ['type' => 'authentication_error']);
        self::assertStringContainsString('API キー', $message);
    }

    #[Test]
    #[TestDox('未知のコード・非オブジェクトは汎用メッセージへフォールバックする')]
    public function fallback(): void
    {
        $unknown = OpenAiErrorMessage::fromError((object) ['code' => 'something_new']);
        self::assertStringContainsString('AI からの応答取得に失敗', $unknown);
        self::assertStringContainsString('AI からの応答取得に失敗', OpenAiErrorMessage::fromError(null));
        self::assertStringContainsString('AI からの応答取得に失敗', OpenAiErrorMessage::fromError('not-an-object'));
    }
}
