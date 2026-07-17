<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * プロバイダ非依存の認証情報バッグ。
 *
 * `apiKey` は全プロバイダ共通の必須項目。それ以外のプロバイダ固有値
 * （例: OpenAI の Organization ID / Project ID）は `attributes` に持たせ、どのキーを
 * 参照するかは各プロバイダ実装が決める。これにより「3 点認証」のようなベンダ固有概念を
 * 汎用契約へ漏らさない。
 */
final class Credentials
{
    /**
     * @param array<string, string> $attributes プロバイダ固有の追加認証値
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly array $attributes = [],
    ) {
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * プロバイダ固有の追加値を取得する。未設定なら空文字を返す。
     */
    public function attribute(string $key): string
    {
        return $this->attributes[$key] ?? '';
    }
}
