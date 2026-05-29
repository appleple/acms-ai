<?php

namespace Acms\Plugins\AI\Services;

use DB;
use SQL;
use Field;
use Config;
use Exception;

class AI
{
    /**
     * @param string $organizationId ChatGPTの組織キー
     * @param string $projectId ChatGPTのプロジェクトキー
     * @param string $apiKey ChatGPTのAPIキー
     * @return array|null $response 使用できるモデルの配列、失敗するとnull
    */
    public function auth(string $organizationId, string $projectId, string $apiKey)
    {
        if (!$organizationId || !$projectId || !$apiKey) {
            return null;
        }

        $url = "https://api.openai.com/v1/models";

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey",
            "OpenAI-Organization: $organizationId",
            "OpenAI-Project: $projectId"
        ];

        $response = null;
        try {
            $ch = curl_init();

            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers
            ];
            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            if ($result === false) {
                throw new \Exception('cURL Error: ' . curl_error($ch));
            }
            $decodedResult = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            } elseif (isset($decodedResult->error)) {
                throw new \Exception("ChatGPT's server error: " . $decodedResult->error->message);
            }

            $response = $this->getModelsByAuthResponse($decodedResult);
        } catch (\Exception $e) {
            \AcmsLogger::error($e->getMessage());
        }

        return $response;
    }

    /**
     * @param Field $config コンフィグのフィールド
     * @return array|null $result 認証キーの配列｜失敗するとnull
    */
    public function getCertification(Field $config)
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
     * @return Field|null $result プロンプト｜失敗するとnull
    */
    public function getConfig()
    {
        $config = Config::loadDefaultField();
        $config->overload(Config::loadBlogConfig(BID));
        return $config;
    }

    /**
     * @param object $result
     * @return array $models
     */
    private function getModelsByAuthResponse(object $result)
    {
        $models = [];
        foreach ($result->data as $datum) {
            if ($this->availableModel($datum->id)) {
                $models[] = $datum->id;
            }
        }
        return $models;
    }

    /**
     * @param string $model モデル名
     * @return string|null $available 利用可能ならモデル名を返し、利用できないならnullを返す。
     */
    public function availableModel(string $model)
    {
        if (!$model) {
            return null;
        }
        $availableModels = ['gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano'];
        $available = null;
        if (in_array($model, $availableModels, true)) {
            $available = $model;
        }
        return $available;
    }

    /**
     * @return array $result タグの配列
     */
    public static function getTagNameAll()
    {
        $result = [];
        try {
            $DB = DB::singleton(dsn());
            $SQL = SQL::newSelect('tag');
            $SQL->addSelect('tag_name');
            $q = $SQL->get(dsn());
            $tagNameArr = $DB->query($q, 'all');
            foreach ($tagNameArr as $row) {
                $result[] = $row["tag_name"];
            }
        } catch (Exception $e) {
            \AcmsLogger::error($e->getMessage());
            return $result;
        }

        $result = array_values(array_unique($result, SORT_REGULAR));
        return $result;
    }
}
