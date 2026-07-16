<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Integration\Services;

use Acms\Plugins\AI\Services\AI;
use Acms\TestingFramework\DatabaseTestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 既定コンフィグにブログ別コンフィグを上書きして返す AI::getConfig の DB 挙動を検証する。
 *
 * Config::loadDefaultField()（設定ファイル）に Config::loadBlogConfig(BID)（acms_config テーブル）を
 * overload する経路のため DB を必要とする。ここでは「Field を返す」ことだけを担保し、個々の設定値は
 * 実機/E2E で確認する。
 */
final class GetConfigTest extends DatabaseTestCase
{
    #[Test]
    #[TestDox('既定コンフィグとブログコンフィグを合成した Field を返す')]
    public function returnsMergedConfigField(): void
    {
        $config = (new AI())->getConfig();

        $this->assertInstanceOf(Field::class, $config);
    }
}
