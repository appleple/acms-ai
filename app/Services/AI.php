<?php

namespace Acms\Plugins\AI\Services;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Config;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use SQL;
use Field;
use Exception;

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
     * 画像解析（vision）で使うモデル名を解決する。
     *
     * 画像解析モデル（`ai_vision_model`）が設定されていればそれを優先し、
     * 空なら通常モデル（`ai_model`）へフォールバックする（既存設定を壊さないため）。
     * さくらのAI Engine のように用途でモデルを使い分けたいプロバイダ向けの設定。
     */
    public function visionModel(Field $config): string
    {
        $model = trim($config->get('ai_vision_model'));

        return $model !== '' ? $model : trim($config->get('ai_model'));
    }

    /**
     * 指定ブログのタグ名一覧を返す。
     *
     * ブログを絞らないと別ブログ（別テナント）のタグ名まで AI プロバイダへ
     * 送信してしまうため、現在のブログに限定する。
     *
     * @return list<string> $result タグの配列
     */
    public static function getTagNameAll(int $blogId): array
    {
        $result = [];
        try {
            $DB = Database::singleton(dsn());
            $SQL = SQL::newSelect('tag');
            $SQL->addSelect('tag_name');
            $SQL->addWhereOpr('tag_blog_id', $blogId);
            $q = $SQL->get(dsn());
            $tagNameArr = $DB->query($q, 'all');
            if (is_iterable($tagNameArr)) {
                foreach ($tagNameArr as $row) {
                    if (is_array($row) && isset($row['tag_name'])) {
                        $result[] = (string) $row['tag_name'];
                    }
                }
            }
        } catch (Exception $e) {
            Logger::error('【AI plugin】 タグ一覧の取得に失敗しました', Common::exceptionArray($e));
            return $result;
        }

        $result = array_values(array_unique($result, SORT_REGULAR));
        return $result;
    }
}
