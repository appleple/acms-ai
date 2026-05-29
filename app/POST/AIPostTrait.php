<?php

namespace Acms\Plugins\AI\POST;

use Common;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Endpoints\ResponsesClient;

trait AIPostTrait
{
    /**
     * @var string
     */
    protected $apiKey = "";

    /**
     * @var string
     */
    protected $model = "gpt-4o-mini";

    protected function initAiConfig(): void
    {
        try {
            $ServiceAI = new ServicesAI();
            $config = $ServiceAI->getConfig();
            $cert = $ServiceAI->getCertification($config);
            if ($cert['ai_api_key'] && $cert['ai_model']) {
                $this->apiKey = $cert['ai_api_key'];
                $this->model = $cert['ai_model'];
            }
        } catch (\Exception $e) {
            \AcmsLogger::error($e->getMessage());
        }
    }

    protected function injectAdditionalMessages(ResponsesClient $_client): void
    {
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function errorResponse(string $message, array $logContext = []): mixed
    {
        $response = ['message' => $message, 'errorCode' => 500];
        \AcmsLogger::notice($message, empty($logContext) ? $response : $logContext);
        return Common::responseJson($response);
    }

    protected function executeAiRequest(string $instructions, string $schemaName, array $promptMessages): mixed
    {
        if (!$this->apiKey || !$this->model) {
            return $this->errorResponse('APIキーまたはモデルの設定がありません。');
        }

        $client = new ResponsesClient($this->apiKey, $this->model);
        $client->createPayload();

        $client->setInstructions($instructions);

        $this->injectAdditionalMessages($client);

        foreach ($promptMessages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $client->addInput($role, [
                $client->createTextContent($content, $role)
            ]);
        }

        $client->setTextFormat([
            'type' => 'json_schema',
            'name' => $schemaName,
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'content' => ['type' => 'string']
                            ],
                            'required' => ['content'],
                            'additionalProperties' => false
                        ]
                    ]
                ],
                'required' => ['items'],
                'additionalProperties' => false
            ]
        ]);

        $result = $client->request();
        if ($result === null) {
            return $this->errorResponse('データを取得できませんでした。');
        }
        $text = ResponsesClient::extractText($result);

        if (!$text) {
            return $this->errorResponse('データを取得できませんでした。');
        }

        $decoded = json_decode($text, true);
        if (!$decoded || !isset($decoded['items'])) {
            return $this->errorResponse('有効な形式のデータを取得できませんでした。', ['response' => $text]);
        }

        return Common::responseJson($decoded['items']);
    }
}
