<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\ModelFilter;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * config のパターン設定によるモデル一覧の絞り込み（ModelFilter）を固定する。
 *
 * 空パターンは素通し、完全一致とワイルドカード、複数パターン（空白・カンマ・改行区切り）、
 * どのパターンにも合わない場合は空配列、API の返却順序の維持を保証する。
 */
final class ModelFilterTest extends TestCase
{
    private const MODELS = ['gpt-5.4', 'gpt-5.4-mini', 'whisper-large-v3-turbo', 'llm-jp-3.1-8x13b-instruct4'];

    #[Test]
    #[TestDox('パターンが空文字・空白のみならフィルタせず全モデルを返す')]
    public function emptyPatternsReturnAllModels(): void
    {
        self::assertSame(self::MODELS, ModelFilter::filter(self::MODELS, ''));
        self::assertSame(self::MODELS, ModelFilter::filter(self::MODELS, "  \n "));
    }

    #[Test]
    #[TestDox('完全一致のパターンで絞り込める')]
    public function exactMatchFilters(): void
    {
        self::assertSame(['gpt-5.4'], ModelFilter::filter(self::MODELS, 'gpt-5.4'));
    }

    #[Test]
    #[TestDox('ワイルドカード（*）で前方一致などのパターン指定ができる')]
    public function wildcardFilters(): void
    {
        self::assertSame(['gpt-5.4', 'gpt-5.4-mini'], ModelFilter::filter(self::MODELS, 'gpt-5.4*'));
    }

    #[Test]
    #[TestDox('空白・カンマ・改行区切りで複数パターンを指定できる')]
    public function multiplePatternsAreCombined(): void
    {
        self::assertSame(
            ['gpt-5.4', 'gpt-5.4-mini', 'llm-jp-3.1-8x13b-instruct4'],
            ModelFilter::filter(self::MODELS, "gpt-5.4*, \n llm-jp-*")
        );
    }

    #[Test]
    #[TestDox('どのパターンにも一致しなければ空配列を返す')]
    public function noMatchReturnsEmpty(): void
    {
        self::assertSame([], ModelFilter::filter(self::MODELS, 'claude-*'));
    }

    #[Test]
    #[TestDox('結果は API の返却順序を保つ（パターン順ではない）')]
    public function preservesApiOrder(): void
    {
        self::assertSame(
            ['gpt-5.4', 'whisper-large-v3-turbo'],
            ModelFilter::filter(self::MODELS, 'whisper-* gpt-5.4')
        );
    }
}
