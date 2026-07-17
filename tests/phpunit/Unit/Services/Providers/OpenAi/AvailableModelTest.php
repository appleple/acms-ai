<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 利用可能モデルの許可リスト判定（OpenAiProvider::supportsModel）を固定する。
 *
 * 許可リストに含まれるモデル名だけ true を返し、それ以外・空文字は false を返すことを保証する。
 * 設定画面のモデル選択・モデル列挙の絞り込みの土台になる純粋ロジックのため通信せずに検証する。
 */
final class AvailableModelTest extends TestCase
{
    private function provider(): OpenAiProvider
    {
        return new OpenAiProvider(new Credentials('sk-test', ['organizationId' => 'org', 'projectId' => 'proj']));
    }

    #[Test]
    #[TestDox('許可リストのモデルは true を返す')]
    public function allowedModelsReturnTrue(): void
    {
        $provider = $this->provider();
        foreach (['gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano'] as $model) {
            self::assertTrue($provider->supportsModel($model));
        }
    }

    #[Test]
    #[TestDox('許可リストにないモデルは false を返す')]
    public function unknownModelReturnsFalse(): void
    {
        $provider = $this->provider();
        self::assertFalse($provider->supportsModel('gpt-4o-mini'));
        self::assertFalse($provider->supportsModel('gpt-5.4-ultra'));
        self::assertFalse($provider->supportsModel('GPT-5.4'));
    }

    #[Test]
    #[TestDox('空文字は false を返す')]
    public function emptyModelReturnsFalse(): void
    {
        self::assertFalse($this->provider()->supportsModel(''));
    }
}
