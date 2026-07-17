<?php

namespace Acms\Plugins\AI\POST;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;

trait AIPostTrait
{
    /**
     * @var AiProvider|null 解決済みプロバイダ（config の ai_provider で決定）
     */
    protected $provider = null;

    /**
     * @var string 選択中のモデル名
     */
    protected $model = "";

    protected function initAiConfig(): void
    {
        try {
            $ServiceAI = new ServicesAI();
            $config = $ServiceAI->getConfig();
            $this->model = $config->get('ai_model');
            $this->provider = ProviderRegistry::withDefaults()->resolve($config);
        } catch (\Throwable $e) {
            Logger::error('【AI plugin】 AI 設定の初期化に失敗しました', Common::exceptionArray($e));
        }
    }

    /**
     * プロンプトの前に差し込む追加メッセージ（既存タグの提示など）。既定は無し。
     *
     * @return list<Message>
     */
    protected function additionalMessages(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function errorResponse(string $message, array $logContext = []): mixed
    {
        $response = ['message' => $message, 'errorCode' => 500];
        Logger::notice($message, $logContext === [] ? $response : $logContext);
        return Common::responseJson($response);
    }

    /**
     * @param list<array{role?: string, content?: string}> $promptMessages
     */
    protected function executeAiRequest(string $instructions, string $schemaName, array $promptMessages): mixed
    {
        if ($this->provider === null || !$this->provider->isConfigured() || $this->model === '') {
            return $this->errorResponse('APIキーまたはモデルの設定がありません。');
        }

        $messages = $this->additionalMessages();
        foreach ($promptMessages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $messages[] = $role === Message::ROLE_ASSISTANT
                ? Message::assistant(ContentPart::text($content))
                : Message::user(ContentPart::text($content));
        }

        $request = new GenerationRequest(
            $this->model,
            $messages,
            $instructions,
            $this->itemsSchema(),
            $schemaName
        );

        $result = $this->provider->generateText($request);
        $text = $result->text;
        if ($text === null || $text === '') {
            return $this->errorResponse('データを取得できませんでした。');
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !isset($decoded['items'])) {
            return $this->errorResponse('有効な形式のデータを取得できませんでした。', ['response' => $text]);
        }

        return Common::responseJson($decoded['items']);
    }

    /**
     * タイトル／タグ生成が共通で用いる構造化出力スキーマ（{ items: [{ content }] }）。
     *
     * @return array<string, mixed>
     */
    private function itemsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string'],
                        ],
                        'required' => ['content'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }
}
