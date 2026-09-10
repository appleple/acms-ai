<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Logging;

use Acms\Services\Logger\Filter;
use Acms\Plugins\AI\Services\AI\Logging\AuditLogSanitizer;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 監査ログフィルターへの機密キー登録と、旧コア向け POST ボディマスキングを固定する。
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
            'previousResponseId' => 'resp_secret',
            'targets' => 'alt,tags',
            'ACMS_POST_AI_Title' => '1',
        ]);

        self::assertSame('***MASKED***', $masked['article']);
        self::assertSame('***MASKED***', $masked['input']);
        self::assertSame('***MASKED***', $masked['addPrompt']);
        self::assertSame('***MASKED***', $masked['image_url']);
        self::assertSame('***MASKED***', $masked['alreadyGeneratedTags']);
        self::assertSame('***MASKED***', $masked['previousResponseId']);
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
    #[TestDox('コアのログフィルターAPIがあれば $_POST を変更せず機密キーを登録する')]
    public function registersCoreFilterWithoutMutatingPostBody(): void
    {
        $filter = new class {
            public string $target = '';

            /** @var list<string> */
            public array $keys = [];

            /**
             * @param list<string> $keys
             */
            public function registerSensitiveKeys(string $target, array $keys): void
            {
                $this->target = $target;
                $this->keys = $keys;
            }
        };
        $original = $_POST;
        try {
            $_POST = ['article' => '本文', 'formToken' => 'tok'];
            (new AuditLogSanitizer($filter))->protectRequestBody();

            self::assertSame(['article' => '本文', 'formToken' => 'tok'], $_POST);
            self::assertSame('field', $filter->target);
            self::assertContains('article', $filter->keys);
            self::assertContains('previousresponseid', $filter->keys);
        } finally {
            $_POST = $original;
        }
    }

    #[Test]
    #[TestDox('登録したAI機密キーをコアのログフィルターが実際にマスクする')]
    public function registeredKeysAreMaskedByCoreFilter(): void
    {
        $filter = new Filter();
        $original = $_POST;
        try {
            $_POST = [
                'article' => '記事本文',
                'input' => 'チャット入力',
                'previousResponseId' => 'resp_secret',
                'targets' => 'title',
            ];
            (new AuditLogSanitizer($filter))->protectRequestBody();

            self::assertSame('記事本文', $_POST['article']);
            $masked = $filter->getSafeArray($_POST);
            self::assertSame('***MASKED***', $masked['article']);
            self::assertSame('***MASKED***', $masked['input']);
            self::assertSame('***MASKED***', $masked['previousResponseId']);
            self::assertSame('title', $masked['targets']);
        } finally {
            $_POST = $original;
        }
    }

    #[Test]
    #[TestDox('コアのログフィルターAPIがなければ $_POST を冪等にマスクする')]
    public function fallsBackToMaskingPostBodyIdempotently(): void
    {
        $original = $_POST;
        try {
            $_POST = ['article' => '本文', 'formToken' => 'tok'];
            $sanitizer = new AuditLogSanitizer(new \stdClass());
            $sanitizer->protectRequestBody();
            $sanitizer->protectRequestBody();

            self::assertSame('***MASKED***', $_POST['article']);
            self::assertSame('tok', $_POST['formToken']);
        } finally {
            $_POST = $original;
        }
    }
}
