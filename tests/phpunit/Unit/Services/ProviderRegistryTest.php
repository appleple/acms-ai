<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicProvider;
use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatProvider;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * config（ai_provider）からプロバイダを解決する ProviderRegistry の振る舞いを固定する。
 *
 * 未設定・未登録の値は既定（OpenAI）へフォールバックすること、明示指定は対応プロバイダを返すこと、
 * 任意のプロバイダを register() で追加できることを保証する。
 */
final class ProviderRegistryTest extends TestCase
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
    #[TestDox('ai_provider 未設定なら既定の OpenAI プロバイダを返す')]
    public function resolvesOpenAiByDefault(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolve($this->config());

        self::assertInstanceOf(OpenAiProvider::class, $provider);
        self::assertSame('openai', $provider->id());
    }

    #[Test]
    #[TestDox('ai_provider に openai を指定すると OpenAI プロバイダを返す')]
    public function resolvesConfiguredProvider(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolve($this->config(['ai_provider' => 'openai']));

        self::assertSame('openai', $provider->id());
    }

    #[Test]
    #[TestDox('ai_provider に anthropic を指定すると Anthropic プロバイダを返す')]
    public function resolvesAnthropicProvider(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolve($this->config(['ai_provider' => 'anthropic']));

        self::assertInstanceOf(AnthropicProvider::class, $provider);
        self::assertSame('anthropic', $provider->id());
    }

    #[Test]
    #[TestDox('ai_provider に gemini を指定すると Gemini プロバイダを返す')]
    public function resolvesGeminiProvider(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolve($this->config(['ai_provider' => 'gemini']));

        self::assertInstanceOf(GeminiProvider::class, $provider);
        self::assertSame('gemini', $provider->id());
    }

    #[Test]
    #[TestDox('ai_provider に compat を指定すると OpenAI 互換プロバイダを返す')]
    public function resolvesOpenAiCompatProvider(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolve($this->config(['ai_provider' => 'compat']));

        self::assertInstanceOf(OpenAiCompatProvider::class, $provider);
        self::assertSame('compat', $provider->id());
    }

    #[Test]
    #[TestDox('未登録のプロバイダ id は既定へフォールバックする')]
    public function fallsBackToDefaultForUnknownProvider(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolve($this->config(['ai_provider' => 'unknown-xyz']));

        self::assertSame('openai', $provider->id());
    }

    #[Test]
    #[TestDox('ids は登録済みプロバイダ id を登録順で返す')]
    public function idsReturnsRegisteredProviderIds(): void
    {
        self::assertSame(
            ['openai', 'anthropic', 'gemini', 'compat'],
            ProviderRegistry::withDefaults()->ids()
        );
    }

    #[Test]
    #[TestDox('resolveById は指定 id のプロバイダを返す（フォールバックなし）')]
    public function resolveByIdReturnsSpecifiedProvider(): void
    {
        $provider = ProviderRegistry::withDefaults()->resolveById('gemini', $this->config());

        self::assertInstanceOf(GeminiProvider::class, $provider);
        self::assertSame('gemini', $provider->id());
    }

    #[Test]
    #[TestDox('resolveById は未登録の id で例外を投げる')]
    public function resolveByIdThrowsForUnknownProvider(): void
    {
        $this->expectException(\RuntimeException::class);

        ProviderRegistry::withDefaults()->resolveById('unknown-xyz', $this->config());
    }

    #[Test]
    #[TestDox('register で追加したプロバイダを解決できる')]
    public function customRegistrationResolves(): void
    {
        $registry = new ProviderRegistry();
        $stub = new OpenAiProvider(
            new \Acms\Plugins\AI\Services\AI\Contracts\Credentials('sk', ['organizationId' => 'o', 'projectId' => 'p'])
        );
        $registry->register('stub', static fn(Field $config): AiProvider => $stub);

        self::assertTrue($registry->has('stub'));
        self::assertSame($stub, $registry->resolve($this->config(['ai_provider' => 'stub'])));
    }
}
