<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 画像解析（vision）で使うモデル名の解決（ai_vision_model 優先・空なら ai_model へ
 * フォールバック）を固定する。既存設定（ai_model のみ）を壊さないための互換性が要点。
 */
final class VisionModelTest extends TestCase
{
    /**
     * @param array<string, string> $values
     */
    private function config(array $values = []): Field
    {
        $field = new Field();
        foreach ($values as $key => $value) {
            $field->set($key, $value);
        }
        return $field;
    }

    #[Test]
    #[TestDox('ai_vision_model が設定されていれば優先する')]
    public function prefersVisionModelWhenSet(): void
    {
        $config = $this->config([
            'ai_model' => 'text-model',
            'ai_vision_model' => 'vision-model',
        ]);

        self::assertSame('vision-model', (new AI())->visionModel($config));
    }

    #[Test]
    #[TestDox('ai_vision_model が空（未設定・空白のみ）なら ai_model へフォールバックする')]
    public function fallsBackToTextModel(): void
    {
        $service = new AI();

        self::assertSame('text-model', $service->visionModel($this->config(['ai_model' => 'text-model'])));
        self::assertSame('text-model', $service->visionModel($this->config([
            'ai_model' => 'text-model',
            'ai_vision_model' => '   ',
        ])));
    }

    #[Test]
    #[TestDox('どちらも未設定なら空文字を返す（呼び出し側が未設定エラーにする）')]
    public function returnsEmptyWhenNothingConfigured(): void
    {
        self::assertSame('', (new AI())->visionModel($this->config()));
    }
}
