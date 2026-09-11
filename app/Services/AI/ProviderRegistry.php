<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicProvider;
use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatProvider;
use Field;
use RuntimeException;

/**
 * 利用可能な AI プロバイダのレジストリ。
 *
 * id → ファクトリの登録と、config（`ai_provider`）に基づく解決を担う。新しいプロバイダを
 * 追加する際は {@see self::withDefaults()} に register() を 1 行足すだけで消費側に波及しない。
 */
final class ProviderRegistry
{
    public const DEFAULT_PROVIDER = 'openai';

    /** @var array<string, callable(Field): AiProvider> */
    private array $factories = [];

    /** @var array<string, string> */
    private array $labels = [];

    /**
     * @param callable(Field): AiProvider $factory config を受け取りプロバイダを生成する
     */
    public function register(string $id, callable $factory, ?string $label = null): void
    {
        $this->factories[$id] = $factory;
        $this->labels[$id] = $label ?? $id;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * 登録済みプロバイダの表示用メタデータを登録順で返す。
     *
     * @return list<array{id: string, label: string}>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach (array_keys($this->factories) as $id) {
            $definitions[] = [
                'id' => $id,
                'label' => $this->labels[$id],
            ];
        }

        return $definitions;
    }

    /**
     * config の `ai_provider` に対応するプロバイダを生成して返す。
     * 未設定・未登録の値なら既定（OpenAI）へフォールバックする。
     */
    public function resolve(Field $config): AiProvider
    {
        $id = $config->get('ai_provider');
        if ($id === '' || !isset($this->factories[$id])) {
            $id = self::DEFAULT_PROVIDER;
        }

        return $this->resolveById($id, $config);
    }

    /**
     * 指定した id のプロバイダを、フォールバックせずに生成する。
     */
    public function resolveById(string $id, Field $config): AiProvider
    {
        if (!isset($this->factories[$id])) {
            throw new RuntimeException("No AI provider registered for id: {$id}");
        }

        return ($this->factories[$id])($config);
    }

    /**
     * 既定プロバイダを登録済みのレジストリを返す。
     * 新しいプロバイダを追加する際はここへ register() を足す。
     */
    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(
            self::DEFAULT_PROVIDER,
            static fn(Field $config): AiProvider => OpenAiProvider::fromConfig($config),
            'OpenAI'
        );
        $registry->register(
            AnthropicProvider::ID,
            static fn(Field $config): AiProvider => AnthropicProvider::fromConfig($config),
            'Anthropic (Claude)'
        );
        $registry->register(
            GeminiProvider::ID,
            static fn(Field $config): AiProvider => GeminiProvider::fromConfig($config),
            'Google (Gemini)'
        );
        $registry->register(
            OpenAiCompatProvider::ID,
            static fn(Field $config): AiProvider => OpenAiCompatProvider::fromConfig($config),
            'OpenAI互換（さくらのAI Engine など）'
        );

        return $registry;
    }
}
