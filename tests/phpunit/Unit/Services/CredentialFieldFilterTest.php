<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\CredentialFieldFilter;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * API キー欄の write-only 運用（CredentialFieldFilter）を固定する。
 *
 * 保存済みキーを画面へ再表示しない代わりに、空欄保存では既存値を維持し、
 * 削除は明示的なチェックでのみ行えることを保証する。
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
    #[TestDox('空欄で保存すると既存のキーが維持される')]
    public function emptyInputKeepsSavedKey(): void
    {
        $post = $this->field(['ai_api_key' => '']);
        $saved = $this->field(['ai_api_key' => 'sk-saved']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('sk-saved', $post->get('ai_api_key'));
    }

    #[Test]
    #[TestDox('入力があれば新しいキーとして保存される（前後の空白は除去）')]
    public function inputReplacesSavedKey(): void
    {
        $post = $this->field(['ai_anthropic_api_key' => '  sk-ant-new  ']);
        $saved = $this->field(['ai_anthropic_api_key' => 'sk-ant-old']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('sk-ant-new', $post->get('ai_anthropic_api_key'));
    }

    #[Test]
    #[TestDox('削除チェックを付けて空欄で保存するとキーが削除される')]
    public function deleteCheckboxClearsSavedKey(): void
    {
        $post = $this->field(['ai_gemini_api_key' => '', 'ai_gemini_api_key_delete' => 'on']);
        $saved = $this->field(['ai_gemini_api_key' => 'AIza-saved']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('', $post->get('ai_gemini_api_key'));
    }

    #[Test]
    #[TestDox('削除チェックがあっても入力があれば入力を優先する')]
    public function inputTakesPrecedenceOverDelete(): void
    {
        $post = $this->field(['ai_compat_api_key' => 'new-key', 'ai_compat_api_key_delete' => 'on']);
        $saved = $this->field(['ai_compat_api_key' => 'old-key']);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('new-key', $post->get('ai_compat_api_key'));
    }

    #[Test]
    #[TestDox('保存済みが無く空欄なら空のまま（新規サイトの挙動を変えない）')]
    public function emptyStaysEmptyWhenNothingSaved(): void
    {
        $post = $this->field(['ai_api_key' => '']);
        $saved = $this->field([]);

        (new CredentialFieldFilter())->apply($post, $saved);

        self::assertSame('', $post->get('ai_api_key'));
    }
}
