<?php

namespace Acms\Plugins\AI\Services;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Config;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Logger;
use SQL;
use Field;
use Exception;

class AI
{
    /**
     * @param string $organizationId ChatGPTの組織キー
     * @param string $projectId ChatGPTのプロジェクトキー
     * @param string $apiKey ChatGPTのAPIキー
     * @return list<string>|null $response 使用できるモデルの配列、失敗するとnull
    */
    public function auth(string $organizationId, string $projectId, string $apiKey): ?array
    {
        if ($organizationId === '' || $projectId === '' || $apiKey === '') {
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
            $result = $this->httpGetJson($url, $headers);
            $decodedResult = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }
            // Why not: 元は object 前提で getModelsByAuthResponse() に素通ししていたが、非オブジェクト応答
            // （配列やスカラ）では TypeError で fatal していた。catch(\Exception) では拾えないため、ここで
            // 明示的に弾いてログ＋null 返却の安全側に倒す。
            if (!$decodedResult instanceof \stdClass) {
                throw new \Exception('Unexpected response from ChatGPT server.');
            }
            if (isset($decodedResult->error)) {
                throw new \Exception("ChatGPT's server error: " . $decodedResult->error->message);
            }

            $response = $this->getModelsByAuthResponse($decodedResult);
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 モデル一覧の取得に失敗しました', Common::exceptionArray($e));
        }

        return $response;
    }

    /**
     * OpenAI の API へ GET し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
     * 実通信を切り離すためのシームで、テストではこのメソッドを差し替えて auth() の解析・分岐を検証する。
     *
     * @param list<string> $headers HTTP ヘッダ
     * @return string レスポンスボディ
     * @throws \Exception cURL 実行に失敗した場合
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function httpGetJson(string $url, array $headers): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $result = curl_exec($ch);
        if (!is_string($result)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
        return $result;
    }

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
     * @param \stdClass $result models エンドポイントのデコード済み応答
     * @return list<string> $models 利用可能なモデル名の配列
     */
    private function getModelsByAuthResponse(\stdClass $result): array
    {
        $models = [];
        if (!isset($result->data) || !is_iterable($result->data)) {
            return $models;
        }
        foreach ($result->data as $datum) {
            if (is_object($datum) && isset($datum->id) && $this->availableModel((string) $datum->id) !== null) {
                $models[] = (string) $datum->id;
            }
        }
        return $models;
    }

    /**
     * @param string $model モデル名
     * @return string|null $available 利用可能ならモデル名を返し、利用できないならnullを返す。
     */
    public function availableModel(string $model): ?string
    {
        if ($model === '') {
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
     * @return list<string> $result タグの配列
     */
    public static function getTagNameAll(): array
    {
        $result = [];
        try {
            $DB = Database::singleton(dsn());
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
            Logger::error('【AI plugin】 タグ一覧の取得に失敗しました', Common::exceptionArray($e));
            return $result;
        }

        $result = array_values(array_unique($result, SORT_REGULAR));
        return $result;
    }
}
