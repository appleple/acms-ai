<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Logging;

use Acms\Plugins\AI\Services\AI\Logging\AuditLogSanitizer;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 監査ログへ残る POST ボディのマスキング（コンテンツ系フィールドの秘匿・巨大値の切り詰め）を固定する。
 */
final class AuditLogSanitizerTest extends TestCase
{
    #[Test]
    #[TestDox('記事本文・チャット入力・プロンプト・画像URL などのコンテンツ系フィールドをマスクする')]
    public function masksContentFields(): void
    {
        $masked = AuditLogSanitizer::maskPostBody([
            'article' => '機密の記事本文',
            'input' => 'チャットの入力',
            'addPrompt' => 'カスタムプロンプト',
            'image_url' => 'https://example.com/secret.jpg',
            'alreadyGeneratedTags' => '["A","B"]',
            'targets' => 'alt,tags',
            'ACMS_POST_AI_Title' => '1',
        ]);

        self::assertSame('***MASKED***', $masked['article']);
        self::assertSame('***MASKED***', $masked['input']);
        self::assertSame('***MASKED***', $masked['addPrompt']);
        self::assertSame('***MASKED***', $masked['image_url']);
        self::assertSame('***MASKED***', $masked['alreadyGeneratedTags']);
        // コンテンツではない運用値はそのまま残す（原因調査に必要）
        self::assertSame('alt,tags', $masked['targets']);
        self::assertSame('1', $masked['ACMS_POST_AI_Title']);
    }

    #[Test]
    #[TestDox('ネストした配列の中のコンテンツ系フィールドもマスクする')]
    public function masksNestedContent(): void
    {
        $masked = AuditLogSanitizer::maskPostBody([
            'outer' => ['article' => '本文', 'other' => 'keep'],
        ]);

        self::assertSame('***MASKED***', $masked['outer']['article']);
        self::assertSame('keep', $masked['outer']['other']);
    }

    #[Test]
    #[TestDox('マスク対象外でも巨大な文字列は安全上限で切り詰める')]
    public function truncatesHugeValues(): void
    {
        $masked = AuditLogSanitizer::maskPostBody(['huge' => str_repeat('あ', 2000)]);

        self::assertIsString($masked['huge']);
        self::assertSame(1003, mb_strlen($masked['huge']));
        self::assertStringEndsWith('...', $masked['huge']);
    }

    #[Test]
    #[TestDox('protectRequestBody は $_POST を冪等にマスク済みへ置き換える')]
    public function protectRequestBodyIsIdempotent(): void
    {
        $original = $_POST;
        try {
            $_POST = ['article' => '本文', 'formToken' => 'tok'];
            AuditLogSanitizer::protectRequestBody();
            AuditLogSanitizer::protectRequestBody();

            self::assertSame('***MASKED***', $_POST['article']);
            self::assertSame('tok', $_POST['formToken']);
        } finally {
            $_POST = $original;
        }
    }
}
