<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\POST;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Response;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\AiRequestInputLimit;
use Acms\Plugins\AI\Services\AI\AiRequestInputTooLargeException;
use Acms\Plugins\AI\Services\AI\AiRequestRateLimiter;
use Acms\Plugins\AI\Services\AI\StructuredItemsDecoder;
use Acms\Plugins\AI\Services\AI\Logging\AuditLogSanitizer;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Field;

trait AIPostTrait
{
    /** 解決済みプロバイダ（config の ai_provider で決定） */
    protected ?AiProvider $provider = null;

    /** 選択中のモデル名 */
    protected string $model = '';

    /**
     * AI POST 共通の入口。監査ログ保護 → 権限 → レート制限 → 機密設定読込の順を固定する。
     */
    protected function prepareAiRequest(): Field
    {
        (new AuditLogSanitizer())->protectRequestBody();

        if (!sessionWithContribution(BID)) {
            $this->errorResponse(
                'AI 機能を利用する権限がありません。',
                403,
                ['reason' => 'permission_denied', 'blogId' => BID, 'userId' => SUID],
            );
        }

        $userId = (int) SUID;
        if (!AiRequestRateLimiter::fromConfig()->consume(BID, $userId)) {
            $this->errorResponse(
                'AI 機能へのリクエストが集中しています。しばらく待ってから再試行してください。',
                429,
                ['reason' => 'rate_limit_exceeded', 'blogId' => BID, 'userId' => $userId],
            );
        }

        return $this->loadAiConfig();
    }

    private function loadAiConfig(): Field
    {
        try {
            $ServiceAI = new ServicesAI();
            $config = $ServiceAI->getConfig();
            $this->model = $config->get('ai_model');
            $this->provider = ProviderRegistry::withDefaults()->resolve($config);

            return $config;
        } catch (\Throwable $e) {
            Logger::error('【AI plugin】 AI 設定の初期化に失敗しました', Common::exceptionArray($e));
            $this->errorResponse(
                'AI 設定の読み込みに失敗しました。',
                500,
                ['reason' => 'config_initialization_failed'],
            );
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
    protected function errorResponse(string $message, int $status = 500, array $logContext = []): never
    {
        $response = ['message' => $message, 'errorCode' => $status];
        Logger::notice($message, $logContext === [] ? $response : $logContext);
        http_response_code($status);
        Response::json($response);
    }

    protected function assertGenerationInputWithinLimit(GenerationRequest $request): void
    {
        if (!AiRequestInputLimit::fromConfig()->acceptsRequest($request)) {
            $this->errorResponse(
                'AI に送信する入力が大きすぎます。本文または入力内容を短くしてください。',
                413,
                ['reason' => 'input_too_large'],
            );
        }
    }

    /**
     * @param list<array{role?: string, content?: string}> $promptMessages
     */
    protected function executeAiRequest(string $instructions, string $schemaName, array $promptMessages): never
    {
        if ($this->provider === null || !$this->provider->isConfigured() || $this->model === '') {
            $this->errorResponse('APIキーまたはモデルの設定がありません。');
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
        $this->assertGenerationInputWithinLimit($request);

        try {
            $result = $this->provider->generateText($request);
        } catch (AiRequestInputTooLargeException) {
            $this->errorResponse(
                'AI に送信する入力が大きすぎます。本文または入力内容を短くしてください。',
                413,
                ['reason' => 'input_too_large'],
            );
        }
        $text = $result->text;
        if ($text === null || $text === '') {
            $this->errorResponse($result->errorMessage ?? 'データを取得できませんでした。');
        }

        $decoded = (new StructuredItemsDecoder())->decode($text);
        if (!$decoded->succeeded()) {
            $context = [
                'provider' => $this->provider->id(),
                'model' => $this->model,
                'response_bytes' => strlen($text),
                'failure_reason' => $decoded->failureReason,
            ];
            if ($decoded->jsonErrorCode !== null) {
                $context['json_error_code'] = $decoded->jsonErrorCode;
            }
            $this->errorResponse('有効な形式のデータを取得できませんでした。', 502, $context);
        }

        Response::json($decoded->items);
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
