<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\Gemini;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Contracts\ModelListingProvider;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Contracts\TokenUsage;
use Acms\Plugins\AI\Services\AI\Conversation\ConversationStore;
use Acms\Plugins\AI\Services\AI\Vision\DataUrl;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Field;

/**
 * Google Gemini（generateContent API）向けの {@see AiProvider} 実装。
 *
 * プロバイダ非依存の {@see GenerationRequest} を generateContent のペイロードへ変換する処理を内包する。
 * Gemini 固有のワイヤ形状（x-goog-api-key ヘッダー、contents/parts、role=model、systemInstruction、
 * responseSchema、SSE チャンク形状、/v1beta/models 応答）はすべてこのクラス配下（本クラスと
 * {@see GeminiStreamParser} / {@see GeminiErrorMessage}）に閉じる。
 *
 * 認証はキーを URL クエリではなく x-goog-api-key ヘッダーで送る（アクセスログ等へ API キーが
 * 残らないようにするため）。
 *
 * 会話継続: Gemini はサーバー側に会話状態を持たないため、継続トークンをキーに
 * {@see ConversationStore} で履歴を復元・保存してプロバイダ内部で吸収する
 * （docs/adding-a-provider.md「会話継続トークンの扱い」参照）。
 *
 * 構造化出力: generationConfig の responseMimeType=application/json と responseSchema で
 * ネイティブに強制する。Gemini のスキーマは OpenAPI 由来のサブセット（type が大文字・
 * additionalProperties 非対応）のため、JSON Schema からの変換をここで吸収する。
 */
class GeminiProvider implements AiProvider, ModelListingProvider
{
    public const ID = 'gemini';
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ?ConversationStore $conversations = null,
    ) {
    }

    /**
     * config（`ai_gemini_api_key`）から生成する。
     * モデルはリクエストごとに与えられるためここでは読まない。
     */
    public static function fromConfig(Field $config): self
    {
        return new self(new Credentials($config->get('ai_gemini_api_key')));
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
        return $this->credentials->apiKey() !== '';
    }

    /**
     * Gemini の /v1beta/models を叩き、generateContent に対応するモデル名を返す。
     * API キー未設定なら通信せず null。通信・解析に失敗した場合も null。
     *
     * @return list<string>|null
     */
    public function listModels(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $result = $this->httpGetJson(self::BASE . '/models?pageSize=1000', $this->baseHeaders());
            $decoded = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }
            if (!$decoded instanceof \stdClass) {
                throw new \Exception('Unexpected response from Gemini server.');
            }
            if (isset($decoded->error)) {
                throw new \Exception('Gemini server error: ' . GeminiErrorMessage::fromError($decoded->error));
            }

            return $this->modelsFromResponse($decoded);
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 モデル一覧の取得に失敗しました', Common::exceptionArray($e));
            return null;
        }
    }

    public function generateText(GenerationRequest $request): GenerationResult
    {
        $payload = $this->buildPayload($request);
        $url = self::BASE . '/models/' . rawurlencode($request->model) . ':generateContent';
        $raw = json_decode($this->httpPostJson($url, $this->baseHeaders(), $this->encode($payload)));

        // Gemini はエラー時に { error: { code, message, status } } を返す。
        // エラーの実体をログに残し、原因を運用ログから追えるようにする。
        if ($raw instanceof \stdClass && isset($raw->error)) {
            Logger::error('【AI plugin】 Gemini API がエラーを返しました', $this->errorToContext($raw->error));
            return new GenerationResult(null, $raw, errorMessage: GeminiErrorMessage::fromError($raw->error));
        }

        $text = $this->extractText($raw);
        $finishReason = $this->finishReason($raw);

        // エラーではないが本文が取れないケース（セーフティブロック・空出力など）。原因切り分けのため
        // モデルと終了理由を残す。
        if ($text === null || $text === '') {
            Logger::warning('【AI plugin】 Gemini API から本文を取得できませんでした', [
                'model' => $request->model,
                'finishReason' => $finishReason,
            ]);
        }

        // 継続トークンは返さない。単発生成（タイトル/タグ）に会話状態は不要で、
        // チャットの継続は streamText() 側が会話ストアで発行する。
        return new GenerationResult($text, $raw, null, $finishReason, $this->usageFromResponse($raw));
    }

    public function streamText(GenerationRequest $request, callable $onEvent): void
    {
        $history = $this->loadHistory($request->continuationToken);
        $messages = [...$history, ...$request->messages];
        $payload = $this->buildPayload($request, $messages);
        $url = self::BASE . '/models/' . rawurlencode($request->model) . ':streamGenerateContent?alt=sse';

        $assistantText = '';
        $rawBytes = '';
        $sawEvent = false;
        $parser = new GeminiStreamParser();

        $this->httpPostStream(
            $url,
            $this->baseHeaders(),
            $this->encode($payload),
            function (string $bytes) use ($parser, $onEvent, &$assistantText, &$rawBytes, &$sawEvent, $request, $messages): void {
                $rawBytes .= $bytes;
                $parser->feed($bytes, function (StreamEvent $event) use ($onEvent, &$assistantText, &$sawEvent, $request, $messages): void {
                    $sawEvent = true;
                    if ($event->type === StreamEvent::TYPE_DELTA) {
                        $assistantText .= $event->text ?? '';
                        $onEvent($event);
                        return;
                    }
                    if ($event->type === StreamEvent::TYPE_COMPLETED) {
                        // 完了時点で全履歴（送信メッセージ＋今回の応答）を保存し、
                        // 次リクエストで会話を継続するためのトークンを発行して差し替える。
                        $token = $this->conversationStore()->save(
                            $request->continuationToken,
                            [...$messages, Message::assistant(ContentPart::text($assistantText))]
                        );
                        $onEvent(StreamEvent::completed($token));
                        return;
                    }
                    $onEvent($event);
                });
            }
        );

        // リクエスト不正（モデル名誤り等）のとき Gemini は SSE ではなく素の JSON エラーを返す。
        // その場合はイベントが 1 つも出ないため、受信全体をエラーとして解釈しフロントへ通知する。
        if (!$sawEvent) {
            $decoded = json_decode($rawBytes);
            $error = ($decoded instanceof \stdClass && isset($decoded->error)) ? $decoded->error : null;
            if ($error !== null) {
                Logger::error('【AI plugin】 Gemini API がエラーを返しました', $this->errorToContext($error));
            }
            $onEvent(StreamEvent::error(GeminiErrorMessage::fromError($error)));
        }
    }

    /**
     * generateContent のリクエストペイロードを組み立てる。
     *
     * @param list<Message>|null $messages 送信するメッセージ列（null なら $request->messages）
     * @return array<string, mixed>
     */
    private function buildPayload(GenerationRequest $request, ?array $messages = null): array
    {
        if ($messages === null) {
            $messages = $request->messages;
            if ($request->continuationToken !== null) {
                // 非ストリーミングでも継続トークンが与えられたら履歴を復元して文脈を維持する。
                $messages = [...$this->loadHistory($request->continuationToken), ...$messages];
            }
        }

        $payload = [
            'contents' => array_map(
                fn(Message $message): array => [
                    // Gemini の role は user / model（assistant ではない）。
                    'role' => $message->role === Message::ROLE_ASSISTANT ? 'model' : 'user',
                    'parts' => $this->buildParts($message),
                ],
                $messages
            ),
        ];

        if ($request->instructions !== null) {
            $payload['systemInstruction'] = ['parts' => [['text' => $request->instructions]]];
        }

        if ($request->outputSchema !== null) {
            // ネイティブの構造化出力。responseSchema は OpenAPI 由来のサブセットのため変換する。
            $payload['generationConfig'] = [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->toGeminiSchema($request->outputSchema),
            ];
        }

        return $payload;
    }

    /**
     * 1 メッセージ分のコンテンツ断片を generateContent の parts 配列へ変換する。
     * テキストは text パートに、画像は inlineData（base64）パートに振り分ける。
     * 画像が data URL（サーバー側で取得済みのメディア画像など）ならそのまま分解し、
     * 通常の URL なら取得して base64 化する（Gemini は任意 URL の直接参照に対応しないため）。
     *
     * @return list<array<string, mixed>>
     */
    private function buildParts(Message $message): array
    {
        $parts = [];
        foreach ($message->parts as $part) {
            if ($part->type === ContentPart::TYPE_IMAGE) {
                $inline = DataUrl::parse($part->value) ?? $this->fetchInlineImage($part->value);
                if ($inline === null) {
                    throw new \RuntimeException('画像を取得できませんでした: ' . $part->value);
                }
                $parts[] = ['inlineData' => ['mimeType' => $inline['mimeType'], 'data' => $inline['data']]];
                continue;
            }
            $parts[] = ['text' => $part->value];
        }

        return $parts;
    }

    /**
     * JSON Schema を Gemini の responseSchema（OpenAPI 由来のサブセット）へ変換する。
     * type は大文字へ、未対応のキー（additionalProperties 等）は落とす。
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $converted = [];
        foreach ($schema as $key => $value) {
            switch ($key) {
                case 'type':
                    if (is_string($value)) {
                        $converted['type'] = strtoupper($value);
                    }
                    break;
                case 'properties':
                    if (is_array($value)) {
                        $properties = [];
                        foreach ($value as $name => $property) {
                            if (is_array($property)) {
                                $properties[$name] = $this->toGeminiSchema($property);
                            }
                        }
                        $converted['properties'] = $properties;
                    }
                    break;
                case 'items':
                    if (is_array($value)) {
                        $converted['items'] = $this->toGeminiSchema($value);
                    }
                    break;
                case 'required':
                case 'enum':
                case 'description':
                case 'format':
                case 'nullable':
                    $converted[$key] = $value;
                    break;
                default:
                    // additionalProperties / strict など Gemini 非対応のキーは送らない。
                    break;
            }
        }

        return $converted;
    }

    /**
     * 応答の candidates[0].content.parts から本文テキストを連結して取り出す。
     */
    private function extractText(mixed $raw): ?string
    {
        $candidate = $this->firstCandidate($raw);
        if (
            $candidate === null
            || !isset($candidate->content)
            || !$candidate->content instanceof \stdClass
            || !isset($candidate->content->parts)
            || !is_array($candidate->content->parts)
        ) {
            return null;
        }

        $texts = [];
        foreach ($candidate->content->parts as $part) {
            if ($part instanceof \stdClass && isset($part->text) && is_string($part->text)) {
                $texts[] = $part->text;
            }
        }

        return $texts === [] ? null : implode('', $texts);
    }

    private function finishReason(mixed $raw): ?string
    {
        $candidate = $this->firstCandidate($raw);
        if ($candidate === null || !isset($candidate->finishReason) || !is_string($candidate->finishReason)) {
            return null;
        }

        return $candidate->finishReason;
    }

    private function firstCandidate(mixed $raw): ?\stdClass
    {
        if (
            !$raw instanceof \stdClass
            || !isset($raw->candidates)
            || !is_array($raw->candidates)
            || $raw->candidates === []
        ) {
            return null;
        }
        $candidate = $raw->candidates[0];

        return $candidate instanceof \stdClass ? $candidate : null;
    }

    /**
     * /v1beta/models の応答から generateContent 対応モデル名（models/ 接頭辞なし）を取り出す。
     *
     * @return list<string>
     */
    private function modelsFromResponse(\stdClass $result): array
    {
        $models = [];
        if (!isset($result->models) || !is_iterable($result->models)) {
            return $models;
        }
        foreach ($result->models as $model) {
            if (!$model instanceof \stdClass || !isset($model->name) || !is_string($model->name)) {
                continue;
            }
            if (isset($model->supportedGenerationMethods) && is_array($model->supportedGenerationMethods)) {
                if (!in_array('generateContent', $model->supportedGenerationMethods, true)) {
                    continue;
                }
            }
            $name = preg_replace('@\Amodels/@', '', $model->name);
            if (is_string($name) && $name !== '') {
                $models[] = $name;
            }
        }

        return $models;
    }

    /**
     * Gemini のエラーオブジェクト（{ code, message, status }）をログ用の配列へ写す。
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
                'status' => isset($error->status) && is_string($error->status) ? $error->status : null,
                'code' => isset($error->code) && is_int($error->code) ? $error->code : null,
            ],
            static fn($value): bool => $value !== null
        );
    }

    /**
     * usageMetadata（promptTokenCount / candidatesTokenCount / totalTokenCount）を
     * {@see TokenUsage} へ写す。usageMetadata が無ければ null。
     */
    private function usageFromResponse(mixed $raw): ?TokenUsage
    {
        if (!$raw instanceof \stdClass || !isset($raw->usageMetadata) || !$raw->usageMetadata instanceof \stdClass) {
            return null;
        }
        $usage = $raw->usageMetadata;

        return new TokenUsage(
            (int) ($usage->promptTokenCount ?? 0),
            (int) ($usage->candidatesTokenCount ?? 0),
            (int) ($usage->totalTokenCount ?? 0),
        );
    }

    /**
     * 認証ヘッダー。API キーは URL クエリではなくヘッダーで送る（ログへ残さない）。
     *
     * @return list<string>
     */
    private function baseHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->credentials->apiKey(),
        ];
    }

    /**
     * 継続トークンから履歴を復元する。トークンが無ければ空履歴。
     *
     * @return list<Message>
     */
    private function loadHistory(?string $token): array
    {
        if ($token === null || $token === '') {
            return [];
        }

        return $this->conversationStore()->load($token);
    }

    private function conversationStore(): ConversationStore
    {
        return $this->conversations ?? new ConversationStore();
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \RuntimeException JSON へ変換できない場合
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode request payload: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * 画像 URL を取得して inlineData 用の base64 へ変換する。取得できなければ null。
     * curl 依存の I/O 境界。テストではこのメソッドを差し替える。
     *
     * @return array{mimeType: string, data: string}|null
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function fetchInlineImage(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $body = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $mimeType = is_string($contentType) && $contentType !== '' ? $contentType : 'application/octet-stream';
        // "image/jpeg; charset=..." のようなパラメータ付きは MIME 部分だけを使う。
        $mimeType = trim(explode(';', $mimeType)[0]);

        return ['mimeType' => $mimeType, 'data' => base64_encode($body)];
    }

    /**
     * Gemini の API へ GET し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
     * テストではこのメソッドを差し替えて listModels() の解析・分岐を検証する。
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
     * Gemini の API へ POST し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
     * テストではこのメソッドを差し替えてリクエスト変換・レスポンス解析を検証する。
     *
     * @param list<string> $headers
     * @throws \Exception cURL 実行に失敗した場合
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function httpPostJson(string $url, array $headers, string $body): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);
        $result = curl_exec($ch);
        if (!is_string($result)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        return $result;
    }

    /**
     * ストリーミング POST。受信バイト列をそのまま $onBytes へ渡す。curl 依存の I/O 境界。
     * SSE のデコードは {@see GeminiStreamParser} が担い、ここは転送だけを行う。
     * テストではこのメソッドを差し替えて、ワイヤ列（SSE バイト列）を直接注入する。
     *
     * @param list<string> $headers
     * @param callable(string): void $onBytes
     * @throws \Exception cURL 実行に失敗した場合
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function httpPostStream(string $url, array $headers, string $body, callable $onBytes): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($onBytes): int {
                $onBytes($data);
                return strlen($data);
            },
        ]);
        curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
    }
}
