<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 利用可能モデルの許可リスト判定（AI::availableModel）を固定する。
 *
 * 許可リストに含まれるモデル名だけをそのまま返し、それ以外・空文字は null を返すことを保証する。
 * 設定画面のモデル選択・認証判定の土台になる純粋ロジックのため DB を使わずに検証する。
 */
final class AvailableModelTest extends TestCase
{
    #[Test]
    #[TestDox('許可リストのモデルはそのモデル名を返す')]
    public function allowedModelsPassThrough(): void
    {
        $service = new AI();
        foreach (['gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano'] as $model) {
            self::assertSame($model, $service->availableModel($model));
        }
    }

    #[Test]
    #[TestDox('許可リストにないモデルは null を返す')]
    public function unknownModelReturnsNull(): void
    {
        $service = new AI();
        self::assertNull($service->availableModel('gpt-4o-mini'));
        self::assertNull($service->availableModel('gpt-5.4-ultra'));
        self::assertNull($service->availableModel('GPT-5.4'));
    }

    #[Test]
    #[TestDox('空文字は null を返す')]
    public function emptyModelReturnsNull(): void
    {
        self::assertNull((new AI())->availableModel(''));
    }
}
