<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class VisionModelTest extends TestCase
{
    #[Test]
    #[TestDox('画像解析モデルが設定されていれば優先する')]
    public function prefersVisionModel(): void
    {
        $config = $this->config([
            'ai_model' => 'text-model',
            'ai_vision_model' => 'vision-model',
        ]);

        self::assertSame('vision-model', (new AI())->visionModel($config));
    }

    #[Test]
    #[TestDox('画像解析モデルが未設定または空白だけなら通常モデルを使用する')]
    public function fallsBackToDefaultModel(): void
    {
        $service = new AI();

        self::assertSame('text-model', $service->visionModel($this->config([
            'ai_model' => ' text-model ',
        ])));
        self::assertSame('text-model', $service->visionModel($this->config([
            'ai_model' => 'text-model',
            'ai_vision_model' => '   ',
        ])));
    }

    #[Test]
    #[TestDox('どちらのモデルも未設定なら空文字を返す')]
    public function returnsEmptyWhenNoModelIsConfigured(): void
    {
        self::assertSame('', (new AI())->visionModel($this->config()));
    }

    /**
     * @param array<string, string> $values
     */
    private function config(array $values = []): Field
    {
        $config = new Field();
        foreach ($values as $key => $value) {
            $config->set($key, $value);
        }

        return $config;
    }
}
