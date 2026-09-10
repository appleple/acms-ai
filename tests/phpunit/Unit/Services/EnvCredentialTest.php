<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\EnvCredential;
use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicProvider;
use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatProvider;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 環境変数（.env）による認証情報の供給を固定する。
 * env > DB（config）の優先順位と、各プロバイダの fromConfig が環境変数を拾うことが要点。
 */
final class EnvCredentialTest extends TestCase
{
    /** @var array<string, array{exists: bool, value: mixed}> テスト前の環境変数。 */
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $original) {
            if ($original['exists']) {
                $_ENV[$key] = $original['value'];
            } else {
                unset($_ENV[$key]);
            }
        }
        $this->originalEnv = [];
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = [
                'exists' => array_key_exists($key, $_ENV),
                'value' => $_ENV[$key] ?? null,
            ];
        }
        $_ENV[$key] = $value;
    }

    #[Test]
    #[TestDox('環境変数が設定されていれば fallback（config 値）より優先する')]
    public function envWinsOverFallback(): void
    {
        $this->setEnv('ACMS_AI_TEST_KEY', 'from-env');

        self::assertSame('from-env', EnvCredential::get('ACMS_AI_TEST_KEY', 'from-config'));
        self::assertTrue(EnvCredential::isSet('ACMS_AI_TEST_KEY'));
    }

    #[Test]
    #[TestDox('環境変数が未設定・空・空白だけなら fallback を返す')]
    public function fallsBackWhenUnsetEmptyOrWhitespace(): void
    {
        self::assertSame('from-config', EnvCredential::get('ACMS_AI_UNSET_KEY', 'from-config'));
        self::assertFalse(EnvCredential::isSet('ACMS_AI_UNSET_KEY'));

        $this->setEnv('ACMS_AI_EMPTY_KEY', '');
        self::assertSame('from-config', EnvCredential::get('ACMS_AI_EMPTY_KEY', 'from-config'));
        self::assertFalse(EnvCredential::isSet('ACMS_AI_EMPTY_KEY'));

        $this->setEnv('ACMS_AI_WHITESPACE_KEY', '   ');
        self::assertSame('from-config', EnvCredential::get('ACMS_AI_WHITESPACE_KEY', 'from-config'));
        self::assertFalse(EnvCredential::isSet('ACMS_AI_WHITESPACE_KEY'));
    }

    #[Test]
    #[TestDox('OpenAI互換キーは正式名を優先し、Sakura名も互換利用できる')]
    public function resolvesCompatKeyAliasesInPriorityOrder(): void
    {
        $this->setEnv(OpenAiCompatProvider::ENV_API_KEY_SAKURA, 'legacy-key');
        self::assertSame('legacy-key', EnvCredential::getForConfig('ai_compat_api_key', 'db-key'));

        $this->setEnv(OpenAiCompatProvider::ENV_API_KEY, 'primary-key');
        self::assertSame('primary-key', EnvCredential::getForConfig('ai_compat_api_key', 'db-key'));
        self::assertTrue(EnvCredential::isSetForConfig('ai_compat_api_key'));
    }

    #[Test]
    #[TestDox('各プロバイダの fromConfig は config が空でも環境変数のキーで isConfigured になる')]
    public function providersPickUpEnvironmentKeys(): void
    {
        $this->setEnv(OpenAiProvider::ENV_API_KEY, 'sk-env');
        $this->setEnv(OpenAiProvider::ENV_ORGANIZATION_ID, 'org-env');
        $this->setEnv(OpenAiProvider::ENV_PROJECT_ID, 'proj-env');
        $this->setEnv(AnthropicProvider::ENV_API_KEY, 'sk-ant-env');
        $this->setEnv(GeminiProvider::ENV_API_KEY, 'AIza-env');
        $this->setEnv(OpenAiCompatProvider::ENV_API_KEY, 'sakura-env');

        $emptyConfig = new Field();

        self::assertTrue(OpenAiProvider::fromConfig($emptyConfig)->isConfigured());
        self::assertTrue(AnthropicProvider::fromConfig($emptyConfig)->isConfigured());
        self::assertTrue(GeminiProvider::fromConfig($emptyConfig)->isConfigured());
        // compat は base URL 未設定でも既定（さくら）が入るため、キーの env 供給だけで configured になる。
        self::assertTrue(OpenAiCompatProvider::fromConfig($emptyConfig)->isConfigured());
    }

    #[Test]
    #[TestDox('環境変数が無ければ従来どおり config のキーを使う（後方互換）')]
    public function providersStillReadConfigWithoutEnv(): void
    {
        $config = new Field();
        $config->set('ai_anthropic_api_key', 'sk-ant-db');

        self::assertTrue(AnthropicProvider::fromConfig($config)->isConfigured());
        self::assertFalse(AnthropicProvider::fromConfig(new Field())->isConfigured());
    }
}
