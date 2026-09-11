<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Config;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use SQL;
use Field;

/**
 * AI 機能まわりの config 読み込み・保存値の取り出し・タグ一覧取得などの汎用ヘルパ。
 *
 * 認証・モデル一覧・生成といったベンダ固有の処理は各プロバイダ実装
 * （{@see \Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider}）へ移譲済みで、
 * ここにはプロバイダ非依存の設定 plumbing だけを残す。
 */
class AI
{
    /**
     * @return Field $config プロンプトを含むコンフィグ
    */
    public function getConfig(): Field
    {
        $config = Config::loadDefaultField();
        $config->overload(Config::loadBlogConfig(BID));
        return $config;
    }

    /**
     * 画像解析用モデルを解決する。
     *
     * 専用モデルが空または空白だけの場合は、通常モデルへフォールバックする。
     */
    public function visionModel(Field $config): string
    {
        $model = trim($config->get('ai_vision_model'));

        return $model !== '' ? $model : trim($config->get('ai_model'));
    }

    /**
     * @return list<string> $result タグの配列
     */
    public static function getTagNameAll(int $blogId): array
    {
        $result = [];
        try {
            $SQL = SQL::newSelect('tag');
            $SQL->addSelect('tag_name');
            $SQL->addWhereOpr('tag_blog_id', $blogId);
            $SQL->addGroup('tag_name');
            $tagNameArr = Database::query($SQL->get(dsn()), 'all');
            if (is_iterable($tagNameArr)) {
                foreach ($tagNameArr as $row) {
                    if (is_array($row) && isset($row['tag_name'])) {
                        $result[] = (string) $row['tag_name'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::error('【AI plugin】 タグ一覧の取得に失敗しました', Common::exceptionArray($e));
            return $result;
        }

        $result = array_values(array_unique($result, SORT_REGULAR));
        return $result;
    }
}
