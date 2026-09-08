<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\ConfigSystemBlock;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class ConfigSystemBlockTest extends TestCase
{
    private const NEW_BLOCK = <<<'YAML'
#BEGIN_AIConfig
ai_title_prompt: "$1 をそのまま扱う"
#END_AIConfig
YAML;

    #[Test]
    #[TestDox('管理ブロックがなければ既存設定の末尾へ追加する')]
    public function appendsBlock(): void
    {
        $actual = ConfigSystemBlock::upsert("other: true\n", self::NEW_BLOCK);

        self::assertSame("other: true\n\n" . self::NEW_BLOCK . "\n", $actual);
    }

    #[Test]
    #[TestDox('既存ブロックだけを置換し、置換文字列のドル記号と周囲の設定を保つ')]
    public function replacesOnlyManagedBlock(): void
    {
        $config = <<<'YAML'
before: true
#BEGIN_AIConfig
old: value
#END_AIConfig
after: true
YAML;

        $actual = ConfigSystemBlock::upsert($config . "\n", self::NEW_BLOCK);

        self::assertSame(
            "before: true\n" . self::NEW_BLOCK . "\nafter: true\n",
            $actual
        );
    }

    #[Test]
    #[TestDox('重複した管理ブロックを1つへ集約し、間にある設定を保つ')]
    public function consolidatesDuplicateBlocks(): void
    {
        $config = <<<'YAML'
#BEGIN_AIConfig
old: first
#END_AIConfig
between: safe
#BEGIN_AIConfig
old: second
#END_AIConfig
YAML;

        $actual = ConfigSystemBlock::upsert($config . "\n", self::NEW_BLOCK);

        self::assertSame(1, substr_count($actual, '#BEGIN_AIConfig'));
        self::assertStringContainsString('between: safe', $actual);
    }

    #[Test]
    #[TestDox('アンインストールでは管理ブロックだけを除去する')]
    public function removesOnlyManagedBlocks(): void
    {
        $config = "before: true\n" . self::NEW_BLOCK . "\nafter: true\n";

        self::assertSame(
            "before: true\nafter: true\n",
            ConfigSystemBlock::remove($config)
        );
    }

    #[Test]
    #[TestDox('配布設定に正しい管理ブロックがなければ既存設定を変更しない')]
    public function ignoresInvalidSource(): void
    {
        self::assertSame(
            "other: true\n",
            ConfigSystemBlock::upsert("other: true\n", 'ai_title_prompt: invalid')
        );
    }
}
