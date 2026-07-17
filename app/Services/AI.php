<?php

namespace Acms\Plugins\AI\Services;

use DB;
use SQL;
use Field;
use Config;
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
     * @param Field $config コンフィグのフィールド
     * @return array<string, mixed> $result 認証キーの配列
    */
    public function getCertification(Field $config): array
    {
        $organizationId = $config->get('ai_organization_id');
        $projectId = $config->get('ai_project_id');
        $apiKey = $config->get('ai_api_key');
        $model = $config->get('ai_model');

        $result = [
            'ai_organization_id' => $organizationId,
            'ai_project_id' => $projectId,
            'ai_api_key' => $apiKey,
            'ai_model' => $model,
        ];

        return $result;
    }

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
     * @return list<string> $result タグの配列
     */
    public static function getTagNameAll(): array
    {
        $result = [];
        try {
            $DB = DB::singleton(dsn());
            $SQL = SQL::newSelect('tag');
            $SQL->addSelect('tag_name');
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
            \AcmsLogger::error($e->getMessage());
            return $result;
        }

        $result = array_values(array_unique($result, SORT_REGULAR));
        return $result;
    }
}
