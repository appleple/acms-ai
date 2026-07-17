<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 利用可能モデルの許可リスト判定（OpenAiProvider::availableModel）を固定する。
 *
 * 許可リストに含まれるモデル名だけをそのまま返し、それ以外・空文字は null を返すことを保証する。
 * 設定画面のモデル選択・認証判定の土台になる純粋ロジックのため通信せずに検証する。
 */
final class AvailableModelTest extends TestCase
{
    private function provider(): OpenAiProvider
    {
        return new OpenAiProvider(new Credentials('sk-test', ['organizationId' => 'org', 'projectId' => 'proj']));
    }

    #[Test]
    #[TestDox('許可リストのモデルはそのモデル名を返す')]
    public function allowedModelsPassThrough(): void
    {
        $provider = $this->provider();
        foreach (['gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano'] as $model) {
            self::assertSame($model, $provider->availableModel($model));
        }
    }

    #[Test]
    #[TestDox('許可リストにないモデルは null を返す')]
    public function unknownModelReturnsNull(): void
    {
        $provider = $this->provider();
        self::assertNull($provider->availableModel('gpt-4o-mini'));
        self::assertNull($provider->availableModel('gpt-5.4-ultra'));
        self::assertNull($provider->availableModel('GPT-5.4'));
    }

    #[Test]
    #[TestDox('空文字は null を返す')]
    public function emptyModelReturnsNull(): void
    {
        self::assertNull($this->provider()->availableModel(''));
    }
}
