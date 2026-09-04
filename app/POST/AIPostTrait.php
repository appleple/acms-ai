<?php

namespace Acms\Plugins\AI\POST;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Logging\AuditLogSanitizer;
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
        // 監査ログの req_body へ記事本文・チャット入力等が平文で残らないようにする
        // （本体ロガーは notice 以上のログで $_POST を保存する。処理は Field を使うため影響なし）
        AuditLogSanitizer::protectRequestBody();
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
     * AI 機能の利用権限を検証し、権限が無ければエラー応答を返す（あれば null）。
     *
     * CSRF は ACMS_POST 基底が検証するが、それはログイン済みなら誰でも通る。
     * サーバーに設定された API キーの消費を伴うため、現在のブログでエントリーを
     * 作成できる権限（投稿者以上）を明示的に要求する。
     */
    protected function denyUnlessContribution(): mixed
    {
        if (sessionWithContribution()) {
            return null;
        }
        $response = ['message' => 'AI 機能を利用する権限がありません。', 'errorCode' => 403];
        Logger::notice('【AI plugin】 権限のないユーザーからの AI リクエストを拒否しました', $response);
        return Common::responseJson($response);
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
            return $this->errorResponse($result->errorMessage ?? 'データを取得できませんでした。');
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !isset($decoded['items'])) {
            // AI 応答の全文はログへ残さない。応答には記事本文が再出力され得るため、
            // リクエスト側（AuditLogSanitizer）のマスクを応答経由で迂回してしまう。
            // 調査にはサイズ・ハッシュ・JSON エラー種別で応答の同一性と失敗原因を追う
            return $this->errorResponse('有効な形式のデータを取得できませんでした。', [
                'provider' => $this->provider->id(),
                'model' => $this->model,
                'response_bytes' => strlen($text),
                'response_sha1' => sha1($text),
                'json_last_error' => json_last_error_msg(),
            ]);
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
