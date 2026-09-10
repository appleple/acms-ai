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
    #[TestDox('環境変数で供給された認証情報の管理画面フラグをセットする')]
    public function setsEnvironmentCredentialFlags(): void
    {
        $key = 'ACMS_AI_ANTHROPIC_API_KEY';
        $exists = array_key_exists($key, $_ENV);
        $original = $_ENV[$key] ?? null;
        $_ENV[$key] = 'sk-ant-env';

        try {
            $globalVars = new Field();
            (new Hook())->extendsGlobalVars($globalVars);

            self::assertSame('1', $globalVars->get('AI_ANTHROPIC_API_KEY_FROM_ENV'));
            self::assertContains($globalVars->get('AI_OPENAI_API_KEY_FROM_ENV'), ['0', '1']);
            self::assertContains($globalVars->get('AI_COMPAT_API_KEY_FROM_ENV'), ['0', '1']);
        } finally {
            if ($exists) {
                $_ENV[$key] = $original;
            } else {
                unset($_ENV[$key]);
            }
        }
    }
}
