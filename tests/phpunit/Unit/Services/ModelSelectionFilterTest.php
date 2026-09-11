<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\ModelSelectionFilter;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class ModelSelectionFilterTest extends TestCase
{
    #[Test]
    #[TestDox('プロバイダを変更すると以前のモデル名をクリアする')]
    public function clearsModelWhenProviderChanges(): void
    {
        $post = $this->field(['ai_provider' => 'gemini', 'ai_model' => 'gpt-5']);
        $saved = $this->field(['ai_provider' => 'openai', 'ai_model' => 'gpt-5']);

        (new ModelSelectionFilter())->apply($post, $saved);

        self::assertSame('', $post->get('ai_model'));
    }

    #[Test]
    #[TestDox('同じプロバイダなら選択したモデル名を維持する')]
    public function keepsModelWhenProviderIsUnchanged(): void
    {
        $post = $this->field(['ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-pro']);
        $saved = $this->field(['ai_provider' => 'gemini', 'ai_model' => 'gemini-2.0-flash']);

        (new ModelSelectionFilter())->apply($post, $saved);

        self::assertSame('gemini-2.5-pro', $post->get('ai_model'));
    }

    #[Test]
    #[TestDox('旧設定の空プロバイダと明示的なOpenAIは同一として扱う')]
    public function treatsLegacyEmptyProviderAsOpenAi(): void
    {
        $post = $this->field(['ai_provider' => 'openai', 'ai_model' => 'gpt-5']);
        $saved = $this->field(['ai_model' => 'gpt-4.1']);

        (new ModelSelectionFilter())->apply($post, $saved);

        self::assertSame('gpt-5', $post->get('ai_model'));
    }

    #[Test]
    #[TestDox('モデル入力が描画されない状態で保存しても既存モデルを維持する')]
    public function keepsSavedModelWhenInputIsMissing(): void
    {
        $post = $this->field(['ai_provider' => 'anthropic']);
        $saved = $this->field(['ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-4']);

        (new ModelSelectionFilter())->apply($post, $saved);

        self::assertSame('claude-sonnet-4', $post->get('ai_model'));
    }

    #[Test]
    #[TestDox('未登録のプロバイダIDは既定値へ正規化する')]
    public function normalizesUnknownProviderToDefault(): void
    {
        $post = $this->field(['ai_provider' => 'unknown', 'ai_model' => 'unknown-model']);
        $saved = $this->field(['ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-pro']);

        (new ModelSelectionFilter())->apply($post, $saved);

        self::assertSame('openai', $post->get('ai_provider'));
        self::assertSame('', $post->get('ai_model'));
    }

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
}
