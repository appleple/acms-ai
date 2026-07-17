<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Contracts\ModelListingProvider;
use Acms\Plugins\AI\Services\AI\Contracts\TokenUsage;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Field;

/**
 * OpenAI（Responses API）向けの {@see AiProvider} 実装。
 *
 * 認証・モデル一覧（旧 Services\AI::auth 相当）と、プロバイダ非依存の {@see GenerationRequest} を
 * Responses API のペイロードへ変換する処理を内包する。OpenAI 固有のワイヤ形状（input_text/
 * output_text/input_image/text.format/previous_response_id、/v1/models 応答、3 点認証）は
 * すべてこのクラス配下（本クラスと {@see ResponsesClient} / {@see StreamingResponsesClient}）に閉じる。
 */
class OpenAiProvider implements AiProvider, ModelListingProvider
{
    public const ID = 'openai';
    private const MODELS_ENDPOINT = 'https://api.openai.com/v1/models';

    /** @var list<string> このプロバイダで利用を許可するモデル名。 */
    private const ALLOWED_MODELS = ['gpt-5.4', 'gpt-5.4-pro', 'gpt-5.4-mini', 'gpt-5.4-nano'];

    public function __construct(private readonly Credentials $credentials)
    {
    }

    /**
     * config（`ai_api_key` / `ai_organization_id` / `ai_project_id`）から生成する。
     * モデルはリクエストごとに与えられるためここでは読まない。
     */
    public static function fromConfig(Field $config): self
    {
        return new self(new Credentials(
            $config->get('ai_api_key'),
            [
                'organizationId' => $config->get('ai_organization_id'),
                'projectId' => $config->get('ai_project_id'),
            ]
        ));
    }

    public function id(): string
    {
        return self::ID;
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, [
            Capability::TextGeneration,
            Capability::StructuredOutput,
            Capability::VisionInput,
            Capability::Streaming,
        ], true);
    }

    public function isConfigured(): bool
    {
        return $this->credentials->apiKey() !== ''
            && $this->credentials->attribute('organizationId') !== ''
            && $this->credentials->attribute('projectId') !== '';
    }

    public function supportsModel(string $model): bool
    {
        return $model !== '' && in_array($model, self::ALLOWED_MODELS, true);
    }

    /**
     * OpenAI の /v1/models を叩き、許可リストに含まれる利用可能モデル名を返す。
     * 認証情報（API キー・Organization ID・Project ID）が未充足なら通信せず null。
     *
     * @return list<string>|null
     */
    public function listModels(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $apiKey = $this->credentials->apiKey();
        $organizationId = $this->credentials->attribute('organizationId');
        $projectId = $this->credentials->attribute('projectId');

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}",
            "OpenAI-Organization: {$organizationId}",
            "OpenAI-Project: {$projectId}",
        ];

        try {
            $result = $this->httpGetJson(self::MODELS_ENDPOINT, $headers);
            $decoded = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }
            // Why not: object 前提で素通しすると非オブジェクト応答（配列・スカラ）で TypeError となり
            // catch(\Exception) では拾えず fatal する。ここで明示的に弾いてログ＋null の安全側に倒す。
            if (!$decoded instanceof \stdClass) {
                throw new \Exception('Unexpected response from OpenAI server.');
            }
            if (isset($decoded->error)) {
                $message = ($decoded->error instanceof \stdClass && isset($decoded->error->message))
                    ? (string) $decoded->error->message
                    : 'unknown error';
                throw new \Exception("OpenAI server error: " . $message);
            }

            return $this->modelsFromResponse($decoded);
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 モデル一覧の取得に失敗しました', Common::exceptionArray($e));
            return null;
        }
    }

    public function generateText(GenerationRequest $request): GenerationResult
    {
        $client = $this->responsesClient($request->model);
        $client->createPayload();

        if ($request->instructions !== null) {
            $client->setInstructions($request->instructions);
        }

        foreach ($request->messages as $message) {
            $client->addInput($message->role, $this->buildContents($client, $message));
        }

        if ($request->outputSchema !== null) {
            $client->setTextFormat([
                'type' => 'json_schema',
                'name' => $request->outputSchemaName ?? 'response',
                'strict' => true,
                'schema' => $request->outputSchema,
            ]);
        }

        if ($request->continuationToken !== null && $request->continuationToken !== '') {
            $client->setPreviousResponseId($request->continuationToken);
        }

        $raw = $client->request();

        // OpenAI が HTTP 200 でも本文にエラーを返すこと（モデル不存在・認証不備など）がある。
        // 従来はここを素通りして本文だけ見ていたため「取得できません」だけが出て原因が不明だった。
        // エラーの実体（message/type/code）をログに残し、原因を運用ログから追えるようにする。
        if ($raw instanceof \stdClass && isset($raw->error)) {
            Logger::error('【AI plugin】 OpenAI API がエラーを返しました', $this->errorToContext($raw->error));
            return new GenerationResult(null, $raw);
        }

        $text = ResponsesClient::extractText($raw);
        $continuation = ($raw instanceof \stdClass && isset($raw->id) && is_string($raw->id))
            ? $raw->id
            : null;
        $finishReason = ($raw instanceof \stdClass && isset($raw->status) && is_string($raw->status))
            ? $raw->status
            : null;

        // エラーではないが本文が取れないケース（status=incomplete・空出力など）。原因切り分けのため
        // モデルと終了理由を残す。
        if ($text === null || $text === '') {
            Logger::warning('【AI plugin】 OpenAI API から本文を取得できませんでした', [
                'model' => $request->model,
                'finishReason' => $finishReason,
            ]);
        }

        return new GenerationResult($text, $raw, $continuation, $finishReason, $this->usageFromResponse($raw));
    }

    public function streamText(GenerationRequest $request, callable $onEvent): void
    {
        $client = $this->streamingClient($request->model);
        $client->createPayload();

        if ($request->instructions !== null) {
            $client->setInstructions($request->instructions);
        }

        // ストリーミング（チャット）はテキストのみ。画像パートが来ても本文だけを送る。
        foreach ($request->messages as $message) {
            $contents = [];
            foreach ($message->parts as $part) {
                $contents[] = $client->createTextContent($part->value, $message->role);
            }
            $client->addInput($message->role, $contents);
        }

        if ($request->continuationToken !== null && $request->continuationToken !== '') {
            $client->setPreviousResponseId($request->continuationToken);
        }

        $client->stream($onEvent);
    }

    /**
     * 1 メッセージ分のコンテンツ断片を Responses API のコンテンツ配列へ変換する。
     * role によってテキストは input_text/output_text に、画像は input_image に振り分ける。
     *
     * @return list<array<string, mixed>>
     */
    private function buildContents(ResponsesClient $client, Message $message): array
    {
        $contents = [];
        foreach ($message->parts as $part) {
            $contents[] = $part->type === ContentPart::TYPE_IMAGE
                ? $client->createImageContent($part->value)
                : $client->createTextContent($part->value, $message->role);
        }

        return $contents;
    }

    /**
     * /v1/models の応答から利用可能モデル名の配列を取り出す。
     *
     * @return list<string>
     */
    private function modelsFromResponse(\stdClass $result): array
    {
        $models = [];
        if (!isset($result->data) || !is_iterable($result->data)) {
            return $models;
        }
        foreach ($result->data as $datum) {
            if ($datum instanceof \stdClass && isset($datum->id) && $this->supportsModel((string) $datum->id)) {
                $models[] = (string) $datum->id;
            }
        }

        return $models;
    }

    /**
     * OpenAI のエラーオブジェクト（{ message, type, code, param }）をログ用の配列へ写す。
     * 認証情報（API キー等）は含まれないため、そのままログに残してよい。
     *
     * @return array<string, mixed>
     */
    private function errorToContext(mixed $error): array
    {
        if (!$error instanceof \stdClass) {
            return ['error' => $error];
        }

        return array_filter(
            [
                'message' => isset($error->message) && is_string($error->message) ? $error->message : null,
                'type' => isset($error->type) && is_string($error->type) ? $error->type : null,
                'code' => isset($error->code) && is_string($error->code) ? $error->code : null,
                'param' => isset($error->param) && is_string($error->param) ? $error->param : null,
            ],
            static fn($value): bool => $value !== null
        );
    }

    /**
     * Responses API の usage（input_tokens / output_tokens / total_tokens）を {@see TokenUsage} へ写す。
     * usage が無ければ null。
     */
    private function usageFromResponse(mixed $raw): ?TokenUsage
    {
        if (!$raw instanceof \stdClass || !isset($raw->usage) || !$raw->usage instanceof \stdClass) {
            return null;
        }
        $usage = $raw->usage;

        return new TokenUsage(
            (int) ($usage->input_tokens ?? 0),
            (int) ($usage->output_tokens ?? 0),
            (int) ($usage->total_tokens ?? 0),
        );
    }

    /**
     * OpenAI の API へ GET し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
     * テストではこのメソッドを差し替えて authenticate() の解析・分岐を検証する。
     *
     * @param list<string> $headers
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
     * ResponsesClient を生成する。テストで差し替えられるようメソッドに切り出す。
     */
    protected function responsesClient(string $model): ResponsesClient
    {
        return new ResponsesClient($this->credentials->apiKey(), $model);
    }

    /**
     * StreamingResponsesClient を生成する。テストで差し替えられるようメソッドに切り出す。
     */
    protected function streamingClient(string $model): StreamingResponsesClient
    {
        return new StreamingResponsesClient($this->credentials->apiKey(), $model);
    }
}
