<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\CredentialFieldFilter;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * API キー欄の write-only 運用を固定する。
 */
final class CredentialFieldFilterTest extends TestCase
{
    /**
     * @param array<string, string> $values
     */
    private function field(array $values): Field
    {
        $field = new Field();
        foreach ($values as $key => $value) {
            $field->set($key, $value);
        }

        return $field;
    }

    #[Test]
    #[TestDox('空欄で保存すると既存のキーを維持する')]
    public function emptyInputKeepsSavedKey(): void
    {
        $post = $this->field(['ai_api_key' => '']);
        $saved = $this->field(['ai_api_key' => 'sk-saved']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('sk-saved', $post->get('ai_api_key'));
    }

    #[Test]
    #[TestDox('入力があれば前後の空白を除去して新しいキーへ差し替える')]
    public function inputReplacesSavedKey(): void
    {
        $post = $this->field(['ai_anthropic_api_key' => '  sk-ant-new  ']);
        $saved = $this->field(['ai_anthropic_api_key' => 'sk-ant-old']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('sk-ant-new', $post->get('ai_anthropic_api_key'));
    }

    #[Test]
    #[TestDox('Gemini API キーも write-only の対象にする')]
    public function geminiKeyKeepsSavedValueWhenInputIsEmpty(): void
    {
        $post = $this->field(['ai_gemini_api_key' => '']);
        $saved = $this->field(['ai_gemini_api_key' => 'gemini-saved']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('gemini-saved', $post->get('ai_gemini_api_key'));
    }

    #[Test]
    #[TestDox('OpenAI 互換 API キーも write-only の対象にする')]
    public function compatKeyKeepsSavedValueWhenInputIsEmpty(): void
    {
        $post = $this->field(['ai_compat_api_key' => '']);
        $saved = $this->field(['ai_compat_api_key' => 'compat-saved']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('compat-saved', $post->get('ai_compat_api_key'));
    }

    #[Test]
    #[TestDox('削除チェック付きの空欄は保存済みキーを削除する')]
    public function deleteCheckboxClearsSavedKey(): void
    {
        $post = $this->field([
            'ai_anthropic_api_key' => '',
            'ai_anthropic_api_key_delete' => 'on',
        ]);
        $saved = $this->field(['ai_anthropic_api_key' => 'sk-ant-saved']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('', $post->get('ai_anthropic_api_key'));
    }

    #[Test]
    #[TestDox('削除チェックがあっても新しい入力を優先する')]
    public function inputTakesPrecedenceOverDelete(): void
    {
        $post = $this->field([
            'ai_api_key' => 'sk-new',
            'ai_api_key_delete' => 'on',
        ]);
        $saved = $this->field(['ai_api_key' => 'sk-old']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('sk-new', $post->get('ai_api_key'));
    }

    #[Test]
    #[TestDox('保存済みキーがなければ空欄のままにする')]
    public function emptyStaysEmptyWhenNothingSaved(): void
    {
        $post = $this->field(['ai_api_key' => '']);

        (new CredentialFieldFilter())->apply($post, new Field());

        self::assertSame('', $post->get('ai_api_key'));
    }

    #[Test]
    #[TestDox('環境変数で管理中のAPIキーはDB上の保存済み値を維持せず削除する')]
    public function environmentManagedKeyClearsSavedValue(): void
    {
        $key = 'ACMS_AI_ANTHROPIC_API_KEY';
        $exists = array_key_exists($key, $_ENV);
        $original = $_ENV[$key] ?? null;
        $_ENV[$key] = 'sk-ant-env';

        try {
            $post = $this->field(['ai_anthropic_api_key' => '']);
            $saved = $this->field(['ai_anthropic_api_key' => 'sk-ant-saved']);

            (new CredentialFieldFilter())->apply($post, $saved);

            self::assertSame('', $post->get('ai_anthropic_api_key'));
        } finally {
            if ($exists) {
                $_ENV[$key] = $original;
            } else {
                unset($_ENV[$key]);
            }
        }
    }
}
