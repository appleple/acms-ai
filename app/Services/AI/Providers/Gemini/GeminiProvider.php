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
use Acms\Plugins\AI\Services\AI\Logging\ProviderErrorLogContext;
use Acms\Plugins\AI\Services\AI\Conversation\ConversationStore;
use Acms\Plugins\AI\Services\AI\EnvCredential;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Field;

/**
 * Google Gemini（generateContent API）向けの {@see AiProvider} 実装。
 *
 * プロバイダ非依存の {@see GenerationRequest} を generateContent のペイロードへ変換する処理を内包する。
 * Gemini 固有のワイヤ形状（x-goog-api-key ヘッダー、contents/parts、role=model、systemInstruction、
 * responseJsonSchema、SSE チャンク形状、/v1beta/models 応答）はすべてこのクラス配下（本クラスと
 * {@see GeminiStreamParser} / {@see GeminiErrorMessage}）に閉じる。
 *
 * 認証はキーを URL クエリではなく x-goog-api-key ヘッダーで送る（アクセスログ等へ API キーが
 * 残らないようにするため）。
 *
 * 会話継続: Gemini はサーバー側に会話状態を持たないため、継続トークンをキーに
 * {@see ConversationStore} で履歴を復元・保存してプロバイダ内部で吸収する
 * （docs/adding-a-provider.md「会話継続トークンの扱い」参照）。
 *
 * 構造化出力: generationConfig の responseMimeType=application/json と responseJsonSchema で
 * ネイティブに強制する。プロバイダ非依存契約の JSON Schema を欠落なく渡す。
 *
 * 画像入力は、CMS が権限検査済みストレージから読み込んだデータだけを
 * inlineData へ変換する。任意の画像 URL はサーバー側で取得せず拒否する。
 */
class GeminiProvider implements AiProvider, ModelListingProvider
{
    public const ID = 'gemini';

    /** API キーを供給できる環境変数名（.env）。設定されていれば config より優先する。 */
    public const ENV_API_KEY = 'ACMS_AI_GEMINI_API_KEY';
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 180;
    private const MODEL_LIST_TIMEOUT = 30;
    private const STREAM_LOW_SPEED_LIMIT = 1;
    private const STREAM_LOW_SPEED_TIME = 120;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ?ConversationStore $conversations = null,
    ) {
    }

    /**
     * config（`ai_gemini_api_key`）から生成する。環境変数があればそちらを優先する。
     * モデルはリクエストごとに与えられるためここでは読まない。
     */
    public static function fromConfig(Field $config): self
    {
        return new self(new Credentials(
            EnvCredential::getForConfig('ai_gemini_api_key', $config->get('ai_gemini_api_key'))
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
        $responseBody = $this->httpPostJson($url, $this->baseHeaders(), $this->encode($payload));
        $raw = json_decode($responseBody);

        if (json_last_error() !== JSON_ERROR_NONE || !$raw instanceof \stdClass) {
            Logger::error('【AI plugin】 Gemini API の応答を解析できませんでした', [
                'model' => $request->model,
                'jsonError' => json_last_error_msg(),
            ]);
            return new GenerationResult(
                null,
                $raw,
                errorMessage: 'AI からの応答を解析できませんでした。時間をおいて再試行してください。'
            );
        }

        // Gemini はエラー時に { error: { code, message, status } } を返す。
        // 外部メッセージは入力内容を含み得るため記録せず、安全なエラー識別子だけを残す。
        if (isset($raw->error)) {
            Logger::error(
                '【AI plugin】 Gemini API がエラーを返しました',
                ProviderErrorLogContext::from($raw->error)
            );
            return new GenerationResult(null, $raw, errorMessage: GeminiErrorMessage::fromError($raw->error));
        }

        $text = $this->extractText($raw);
        $finishReason = $this->finishReason($raw);
        $responseError = GeminiErrorMessage::fromResponse($raw);

        if ($responseError !== null) {
            Logger::warning('【AI plugin】 Gemini API が生成を完了できませんでした', [
                'model' => $request->model,
                'finishReason' => $finishReason,
                'promptBlockReason' => $this->promptBlockReason($raw),
            ]);
            return new GenerationResult(
                null,
                $raw,
                null,
                $finishReason,
                $this->usageFromResponse($raw),
                $responseError,
            );
        }

        // 正常終了なのに本文が無い場合も成功にしない。消費側が無反応になるのを防ぐ。
        if ($text === null || $text === '') {
            Logger::warning('【AI plugin】 Gemini API から本文を取得できませんでした', [
                'model' => $request->model,
                'finishReason' => $finishReason,
            ]);
            return new GenerationResult(
                null,
                $raw,
                null,
                $finishReason,
                $this->usageFromResponse($raw),
                'AI から本文を取得できませんでした。時間をおいて再試行してください。',
            );
        }

        // 継続トークンは返さない。単発生成（タイトル/タグ）に会話状態は不要で、
        // チャットの継続は streamText() 側が会話ストアで発行する。
        return new GenerationResult($text, $raw, null, $finishReason, $this->usageFromResponse($raw));
    }

    public function streamText(GenerationRequest $request, callable $onEvent): void
    {
        $messages = $this->conversationStore()->messagesForRequest($request);
        $payload = $this->buildPayload($request, $messages);
        $url = self::BASE . '/models/' . rawurlencode($request->model) . ':streamGenerateContent?alt=sse';

        $assistantText = '';
        $rawBytes = '';
        $sawAnyEvent = false;
        $sawTerminalEvent = false;
        $parser = new GeminiStreamParser();

        $this->httpPostStream(
            $url,
            $this->baseHeaders(),
            $this->encode($payload),
            function (string $bytes) use ($parser, $onEvent, &$assistantText, &$rawBytes, &$sawAnyEvent, &$sawTerminalEvent, $request, $messages): void {
                if (!$sawAnyEvent) {
                    $rawBytes .= $bytes;
                }
                $parser->feed($bytes, function (StreamEvent $event) use ($onEvent, &$assistantText, &$sawAnyEvent, &$sawTerminalEvent, $request, $messages): void {
                    $sawAnyEvent = true;
                    if ($event->type === StreamEvent::TYPE_DELTA) {
                        $assistantText .= $event->text ?? '';
                        $onEvent($event);
                        return;
                    }
                    if ($event->type === StreamEvent::TYPE_COMPLETED) {
                        $sawTerminalEvent = true;
                        if ($assistantText === '') {
                            $onEvent(StreamEvent::error(
                                'AI から本文を取得できませんでした。時間をおいて再試行してください。'
                            ));
                            return;
                        }
                        // 完了時点で全履歴（送信メッセージ＋今回の応答）を保存し、
                        // 次リクエストで会話を継続するためのトークンを発行して差し替える。
                        $token = $this->conversationStore()->save(
                            $request->continuationToken,
                            [...$messages, Message::assistant(ContentPart::text($assistantText))]
                        );
                        $onEvent(StreamEvent::completed($token));
                        return;
                    }
                    if ($event->type === StreamEvent::TYPE_ERROR) {
                        $sawTerminalEvent = true;
                    }
                    $onEvent($event);
                });
            }
        );

        // リクエスト不正（モデル名誤り等）のとき Gemini は SSE ではなく素の JSON エラーを返す。
        // その場合はイベントが 1 つも出ないため、受信全体をエラーとして解釈しフロントへ通知する。
        if (!$sawTerminalEvent) {
            $decoded = json_decode($rawBytes);
            $error = ($decoded instanceof \stdClass && isset($decoded->error)) ? $decoded->error : null;
            if ($error !== null) {
                Logger::error(
                    '【AI plugin】 Gemini API がエラーを返しました',
                    ProviderErrorLogContext::from($error)
                );
            }
            $onEvent(StreamEvent::error(
                $error !== null
                    ? GeminiErrorMessage::fromError($error)
                    : 'AI からのストリーミング応答が途中で終了しました。再試行してください。'
            ));
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
            $messages = $this->conversationStore()->messagesForRequest($request);
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
            // JSON Schema を受け取る現行フィールドを使い、additionalProperties 等の制約も保持する。
            $payload['generationConfig'] = [
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $request->outputSchema,
            ];
        }

        return $payload;
    }

    /**
     * 1 メッセージ分のコンテンツ断片を generateContent の parts 配列へ変換する。
     * 信頼済み画像データは inlineData へ変換し、任意の画像 URL は拒否する。
     *
     * @return list<array<string, mixed>>
     */
    private function buildParts(Message $message): array
    {
        $parts = [];
        foreach ($message->parts as $part) {
            if ($part->type === ContentPart::TYPE_IMAGE) {
                throw new \RuntimeException('Gemini プロバイダは画像 URL 入力に対応していません。');
            }
            $parts[] = $part->type === ContentPart::TYPE_IMAGE_DATA
                ? ['inlineData' => ['mimeType' => $part->mimeType, 'data' => $part->value]]
                : ['text' => $part->value];
        }

        return $parts;
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

    private function promptBlockReason(mixed $raw): ?string
    {
        if (
            !$raw instanceof \stdClass
            || !isset($raw->promptFeedback)
            || !$raw->promptFeedback instanceof \stdClass
            || !isset($raw->promptFeedback->blockReason)
            || !is_string($raw->promptFeedback->blockReason)
        ) {
            return null;
        }

        return $raw->promptFeedback->blockReason;
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
            if (
                !isset($model->supportedGenerationMethods)
                || !is_array($model->supportedGenerationMethods)
                || !in_array('generateContent', $model->supportedGenerationMethods, true)
            ) {
                continue;
            }
            $name = preg_replace('@\Amodels/@', '', $model->name);
            if (is_string($name) && $this->supportsStructuredOutputModel($name)) {
                $models[] = $name;
            }
        }

        return $models;
    }

    /**
     * 管理画面のモデル選択はタイトル・タグ生成にも使われるため、構造化出力対応モデルに限定する。
     * Models API は構造化出力の能力を返さないので、Google の対応表にある 2.5 系と 3 系を許可し、
     * 同じ generateContent を公開する画像生成・音声・Live 等の派生モデルは除外する。
     */
    private function supportsStructuredOutputModel(string $model): bool
    {
        if (preg_match('/\Agemini-(?:2\.5-(?:pro|flash(?:-lite)?)|3(?:\.\d+)?-(?:pro|flash(?:-lite)?))(?:-.+)?\z/', $model) !== 1) {
            return false;
        }

        return preg_match('/(?:image|tts|audio|live|computer-use)/i', $model) !== 1;
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
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::MODEL_LIST_TIMEOUT,
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
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
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
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // 総時間ではなく応答停止を検出し、長い生成は許容しながら無期限ハングを防ぐ。
            CURLOPT_LOW_SPEED_LIMIT => self::STREAM_LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME => self::STREAM_LOW_SPEED_TIME,
        ]);
        curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
    }
}
