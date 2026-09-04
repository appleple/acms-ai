<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

/**
 * モデル一覧を config のパターン設定で絞り込む純粋ロジック。
 *
 * パターンは空白・カンマ・改行区切りで複数指定でき、`*` ワイルドカード（fnmatch）を使える
 * （例: `gpt-5.4* llm-jp-*`）。パターンが空ならフィルタせず全モデルを返す。
 * モデル名をコードへハードコードすると新モデルのたびにリリースが必要になるため、
 * 既定値は config.system.yaml に置き、サイト側で上書きできるようにする。
 */
final class ModelFilter
{
    /**
     * @param list<string> $models プロバイダ API が返したモデル名（API の順序を保つ）
     * @param string $patterns 空白・カンマ・改行区切りのパターン列（空 = フィルタなし）
     * @return list<string>
     */
    public static function filter(array $models, string $patterns): array
    {
        $patternList = preg_split('/[\s,]+/', trim($patterns), -1, PREG_SPLIT_NO_EMPTY);
        if ($patternList === false || $patternList === []) {
            return $models;
        }

        return array_values(array_filter(
            $models,
            static function (string $model) use ($patternList): bool {
                foreach ($patternList as $pattern) {
                    if (fnmatch($pattern, $model)) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }
}
