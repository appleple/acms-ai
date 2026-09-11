<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Field;

/**
 * プロバイダが列挙したモデルを、サイト設定の表示許可パターンで絞り込む。
 *
 * プロバイダは API 応答の解析と機能要件による絞り込みを担当し、このクラスは
 * 管理画面へ表示する候補の運用上の絞り込みだけを担当する。
 */
final class ModelListFilter
{
    /**
     * 更新処理を実行しておらず、新しい config キーがまだ存在しない環境向けの互換既定値。
     * 明示的に空文字を設定した場合は全モデルを表示できる。
     *
     * @var array<string, string>
     */
    private const DEFAULT_PATTERNS = [
        ProviderRegistry::DEFAULT_PROVIDER => 'gpt-5.6* gpt-5.5* gpt-5.4*',
    ];

    public function __construct(private readonly Field $config)
    {
    }

    /**
     * `ai_<provider-id>_allowed_models` の空白・カンマ区切りのパターンを適用する。
     * パターン内では `*` のみをワイルドカードとして扱い、空なら全件を返す。
     *
     * @param list<string> $models
     * @return list<string>
     */
    public function apply(string $providerId, array $models): array
    {
        $patterns = $this->patterns($providerId);
        if ($patterns === []) {
            return $models;
        }

        return array_values(array_filter(
            $models,
            static function (string $model) use ($patterns): bool {
                foreach ($patterns as $pattern) {
                    if (self::matches($pattern, $model)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    /**
     * @return list<string>
     */
    private function patterns(string $providerId): array
    {
        $key = "ai_{$providerId}_allowed_models";
        $value = $this->config->isExists($key)
            ? $this->config->get($key)
            : (self::DEFAULT_PATTERNS[$providerId] ?? '');
        $patterns = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return $patterns === false ? [] : $patterns;
    }

    private static function matches(string $pattern, string $model): bool
    {
        $quoted = preg_quote($pattern, '~');
        $expression = '~\A' . str_replace('\\*', '.*', $quoted) . '\z~D';

        return preg_match($expression, $model) === 1;
    }
}
