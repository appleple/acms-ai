<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit;

use Acms\Plugins\AI\Hook;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * グローバル変数へ管理画面 UI の JS/CSS パス（キャッシュバスティング付き）を注入する Hook の挙動を固定する。
 *
 * extendsGlobalVars は cacheBusting() でバンドルのパスを解決し、AI_JS / AI_CSS を GlobalVars（Field）へ
 * セットする。テンプレートから <!-- BEGIN_MODULE --> 等を介さず参照される値のため、キーが確実に入ることを保証する。
 */
final class HookTest extends TestCase
{
    #[Test]
    #[TestDox('AI_JS / AI_CSS をバンドルのパスとして GlobalVars にセットする')]
    public function setsBundlePathsIntoGlobalVars(): void
    {
        $globalVars = new Field();
        (new Hook())->extendsGlobalVars($globalVars);

        $js = $globalVars->get('AI_JS');
        $css = $globalVars->get('AI_CSS');

        self::assertStringContainsString('extension/plugins/AI/bundle/acms-ai.js', $js);
        self::assertStringContainsString('extension/plugins/AI/bundle/acms-ai.css', $css);
    }

    #[Test]
    #[TestDox('メディア AI 生成の有効フラグ（AI_VISION_VALID / AI_VISION_VALID_*）を 1/0 でセットする')]
    public function setsVisionFlagsIntoGlobalVars(): void
    {
        $globalVars = new Field();
        (new Hook())->extendsGlobalVars($globalVars);

        $keys = [
            'AI_VISION_VALID',
            'AI_VISION_VALID_ALT',
            'AI_VISION_VALID_CAPTION',
            'AI_VISION_VALID_MEMO',
            'AI_VISION_VALID_FILENAME',
            'AI_VISION_VALID_TAGS',
        ];
        foreach ($keys as $key) {
            // 値は config に依存するため '1' か '0' のどちらか（キーが確実に入ること）を保証する。
            self::assertContains($globalVars->get($key), ['1', '0'], $key);
        }
    }
}
