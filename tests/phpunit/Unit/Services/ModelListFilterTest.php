<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\ModelListFilter;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class ModelListFilterTest extends TestCase
{
    private const MODELS = [
        'gpt-5.4',
        'gpt-5.4-mini',
        'whisper-large-v3-turbo',
        'llm-jp-3.1-8x13b-instruct4',
    ];

    #[Test]
    #[TestDox('OpenAI の設定が未定義なら3世代の既定パターンで絞り込む')]
    public function missingOpenAiSettingUsesSafeDefault(): void
    {
        $models = [
            'gpt-5.6',
            'gpt-5.6-sol',
            'gpt-5.6-sol-fixture',
            'gpt-5.6-terra',
            'gpt-5.6-luna',
            'gpt-5.6-cyber',
            'gpt-5.5',
            'gpt-5.5-pro',
            'gpt-5.4',
            'gpt-5.4-pro',
            'gpt-5.4-mini',
            'gpt-5.4-nano',
            'gpt-image-1.5',
        ];

        self::assertSame(
            [
                'gpt-5.6',
                'gpt-5.6-sol',
                'gpt-5.6-sol-fixture',
                'gpt-5.6-terra',
                'gpt-5.6-luna',
                'gpt-5.6-cyber',
                'gpt-5.5',
                'gpt-5.5-pro',
                'gpt-5.4',
                'gpt-5.4-pro',
                'gpt-5.4-mini',
                'gpt-5.4-nano',
            ],
            $this->filter([])->apply('openai', $models)
        );
    }

    #[Test]
    #[TestDox('設定を明示的に空にした場合はモデル一覧を変更しない')]
    public function emptyPatternsReturnAllModels(): void
    {
        self::assertSame(
            self::MODELS,
            $this->filter(['ai_openai_allowed_models' => ''])->apply('openai', self::MODELS)
        );
        self::assertSame(
            self::MODELS,
            $this->filter(['ai_openai_allowed_models' => "  \n "])->apply('openai', self::MODELS)
        );
        self::assertSame(
            self::MODELS,
            $this->filter([])->apply('anthropic', self::MODELS)
        );
    }

    #[Test]
    #[TestDox('完全一致と * ワイルドカードを空白・カンマ・改行区切りで指定できる')]
    public function configuredPatternsFilterModels(): void
    {
        $filter = $this->filter([
            'ai_openai_allowed_models' => "gpt-5.4*, \n llm-jp-3.1-8x13b-instruct4",
        ]);

        self::assertSame(
            ['gpt-5.4', 'gpt-5.4-mini', 'llm-jp-3.1-8x13b-instruct4'],
            $filter->apply('openai', self::MODELS)
        );
    }

    #[Test]
    #[TestDox('絞り込み結果はパターン順ではなく API の返却順を保つ')]
    public function preservesProviderOrder(): void
    {
        $filter = $this->filter(['ai_openai_allowed_models' => 'whisper-* gpt-5.4']);

        self::assertSame(
            ['gpt-5.4', 'whisper-large-v3-turbo'],
            $filter->apply('openai', self::MODELS)
        );
    }

    #[Test]
    #[TestDox('プロバイダごとに独立した設定キーを参照する')]
    public function usesProviderSpecificSetting(): void
    {
        $filter = $this->filter([
            'ai_openai_allowed_models' => 'gpt-*',
            'ai_gemini_allowed_models' => 'gemini-2.5-*',
        ]);

        self::assertSame(['gpt-5.4', 'gpt-5.4-mini'], $filter->apply('openai', self::MODELS));
        self::assertSame(
            ['gemini-2.5-pro'],
            $filter->apply('gemini', ['gemini-2.5-pro', 'gemini-3-flash'])
        );
    }

    #[Test]
    #[TestDox('* 以外の glob 記号は通常の文字として扱う')]
    public function treatsOtherGlobCharactersLiterally(): void
    {
        $filter = $this->filter(['ai_openai_allowed_models' => 'model-? model-[a]']);

        self::assertSame(
            ['model-?', 'model-[a]'],
            $filter->apply('openai', ['model-a', 'model-?', 'model-[a]'])
        );
    }

    #[Test]
    #[TestDox('一致するモデルがなければ空配列を返す')]
    public function noMatchReturnsEmptyList(): void
    {
        self::assertSame(
            [],
            $this->filter(['ai_openai_allowed_models' => 'claude-*'])->apply('openai', self::MODELS)
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function filter(array $values): ModelListFilter
    {
        $config = new Field();
        foreach ($values as $key => $value) {
            $config->set($key, $value);
        }

        return new ModelListFilter($config);
    }
}
