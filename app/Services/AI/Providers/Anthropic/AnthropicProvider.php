<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\Anthropic;

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
use Acms\Plugins\AI\Services\AI\EnvCredential;
use Acms\Plugins\AI\Services\AI\Vision\DataUrl;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Field;

/**
 * Anthropic Claude（Messages API）向けの {@see AiProvider} 実装。
 *
 * プロバイダ非依存の {@see GenerationRequest} を Messages API のペイロードへ変換する処理を内包する。
 * Anthropic 固有のワイヤ形状（x-api-key / anthropic-version ヘッダー、content ブロック、tool use に
 * よる構造化出力、SSE イベント名、/v1/models 応答）はすべてこのクラス配下（本クラスと
 * {@see AnthropicStreamParser} / {@see AnthropicErrorMessage}）に閉じる。
 *
 * 会話継続: Anthropic はサーバー側に会話状態を持たないため、継続トークンをキーに
 * {@see ConversationStore} で履歴を復元・保存してプロバイダ内部で吸収する
 * （docs/adding-a-provider.md「会話継続トークンの扱い」参照）。
 *
 * 構造化出力: Messages API にネイティブの JSON Schema 出力は無いため、outputSchema を
 * input_schema とする単一ツールを定義し tool_choice で強制する（tool use 方式）。
 * 応答の tool_use ブロックの input を JSON テキストとして返すので、消費側からは
 * OpenAI の json_schema 出力と同じ「JSON 文字列の text」に見える。
 */
class AnthropicProvider implements AiProvider, ModelListingProvider
{
    public const ID = 'anthropic';

    /** API キーを供給できる環境変数名（.env）。設定されていれば config より優先する。 */
    public const ENV_API_KEY = 'ACMS_AI_ANTHROPIC_API_KEY';

    private const MESSAGES_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const MODELS_ENDPOINT = 'https://api.anthropic.com/v1/models?limit=100';

    /** Messages API の必須バージョンヘッダー。 */
    private const API_VERSION = '2023-06-01';

    /** max_tokens は必須項目。全モデルで受理される安全側の上限を使う。 */
    private const MAX_TOKENS = 4096;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ?ConversationStore $conversations = null,
    ) {
    }

    /**
     * config（`ai_anthropic_api_key`）から生成する。環境変数（{@see self::ENV_API_KEY}）が
     * 設定されていればそちらを優先する。モデルはリクエストごとに与えられるためここでは読まない。
     */
    public static function fromConfig(Field $config): self
    {
        return new self(new Credentials(
            EnvCredential::get(self::ENV_API_KEY, $config->get('ai_anthropic_api_key'))
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
     * Anthropic の /v1/models を叩き、利用可能なモデル名を返す。
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
            $result = $this->httpGetJson(self::MODELS_ENDPOINT, $this->baseHeaders());
            $decoded = json_decode($result);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON decode error: ' . json_last_error_msg());
            }
            if (!$decoded instanceof \stdClass) {
                throw new \Exception('Unexpected response from Anthropic server.');
            }
            if (isset($decoded->error)) {
                throw new \Exception('Anthropic server error: ' . AnthropicErrorMessage::fromError($decoded->error));
            }

            return $this->modelsFromResponse($decoded);
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 モデル一覧の取得に失敗しました', Common::exceptionArray($e));
            return null;
        }
    }

    public function generateText(GenerationRequest $request): GenerationResult
    {
        $payload = $this->buildPayload($request, false);
        $raw = json_decode($this->httpPostJson(self::MESSAGES_ENDPOINT, $this->baseHeaders(), $this->encode($payload)));

        // Anthropic はエラー時に { type: "error", error: { type, message } } を返す。
        // エラーの実体をログに残し、原因を運用ログから追えるようにする。
        if ($raw instanceof \stdClass && isset($raw->error)) {
            Logger::error('【AI plugin】 Anthropic API がエラーを返しました', $this->errorToContext($raw->error));
            return new GenerationResult(null, $raw, errorMessage: AnthropicErrorMessage::fromError($raw->error));
        }

        $structured = $request->outputSchema !== null;
        $text = $this->extractText($raw, $structured);
        $finishReason = ($raw instanceof \stdClass && isset($raw->stop_reason) && is_string($raw->stop_reason))
            ? $raw->stop_reason
            : null;

        // エラーではないが本文が取れないケース（max_tokens 到達・空出力など）。原因切り分けのため
        // モデルと終了理由を残す。
        if ($text === null || $text === '') {
            Logger::warning('【AI plugin】 Anthropic API から本文を取得できませんでした', [
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
        $payload = $this->buildPayload($request, true, $messages);

        $assistantText = '';
        $rawBytes = '';
        $sawEvent = false;
        $parser = new AnthropicStreamParser();

        $this->httpPostStream(
            self::MESSAGES_ENDPOINT,
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

        // リクエスト不正（モデル名誤り等）のとき Anthropic は SSE ではなく素の JSON エラーを返す。
        // その場合はイベントが 1 つも出ないため、受信全体をエラーとして解釈しフロントへ通知する。
        if (!$sawEvent) {
            $decoded = json_decode($rawBytes);
            $error = ($decoded instanceof \stdClass && isset($decoded->error)) ? $decoded->error : null;
            if ($error !== null) {
                Logger::error('【AI plugin】 Anthropic API がエラーを返しました', $this->errorToContext($error));
            }
            $onEvent(StreamEvent::error(AnthropicErrorMessage::fromError($error)));
        }
    }

    /**
     * Messages API のリクエストペイロードを組み立てる。
     *
     * @param list<Message>|null $messages 送信するメッセージ列（null なら $request->messages）
     * @return array<string, mixed>
     */
    private function buildPayload(GenerationRequest $request, bool $stream, ?array $messages = null): array
    {
        if ($messages === null) {
            $messages = $request->messages;
            if ($request->continuationToken !== null) {
                // 非ストリーミングでも継続トークンが与えられたら履歴を復元して文脈を維持する。
                $messages = [...$this->loadHistory($request->continuationToken), ...$messages];
            }
        }

        $payload = [
            'model' => $request->model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => array_map(
                fn(Message $message): array => [
                    'role' => $message->role,
                    'content' => $this->buildContents($message),
                ],
                $messages
            ),
        ];

        if ($request->instructions !== null) {
            $payload['system'] = $request->instructions;
        }

        if ($request->outputSchema !== null) {
            // tool use による構造化出力の強制。スキーマを input_schema とする単一ツールを
            // 定義し、tool_choice でその呼び出しを強制する。
            $name = $request->outputSchemaName ?? 'response';
            $payload['tools'] = [
                [
                    'name' => $name,
                    'description' => 'Return the structured result.',
                    'input_schema' => $request->outputSchema,
                ],
            ];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $name];
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * 1 メッセージ分のコンテンツ断片を Messages API の content ブロック配列へ変換する。
     * テキストは text ブロックに、画像は image ブロックに振り分ける。
     * 画像が data URL（サーバー側で取得済みのメディア画像など）の場合は base64 ソースへ、
     * それ以外は URL ソースへ変換する。
     *
     * @return list<array<string, mixed>>
     */
    private function buildContents(Message $message): array
    {
        $contents = [];
        foreach ($message->parts as $part) {
            if ($part->type !== ContentPart::TYPE_IMAGE) {
                $contents[] = ['type' => 'text', 'text' => $part->value];
                continue;
            }
            $inline = DataUrl::parse($part->value);
            $contents[] = $inline !== null
                ? [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $inline['mimeType'],
                        'data' => $inline['data'],
                    ],
                ]
                : ['type' => 'image', 'source' => ['type' => 'url', 'url' => $part->value]];
        }

        return $contents;
    }

    /**
     * 応答の content ブロックから本文を取り出す。
     * 構造化出力（tool use 強制）時は tool_use ブロックの input を JSON テキストへ戻し、
     * 自由文のときは text ブロックを連結する。
     */
    private function extractText(mixed $raw, bool $structured): ?string
    {
        if (!$raw instanceof \stdClass || !isset($raw->content) || !is_array($raw->content)) {
            return null;
        }

        if ($structured) {
            foreach ($raw->content as $block) {
                if (
                    $block instanceof \stdClass
                    && isset($block->type) && $block->type === 'tool_use'
                    && isset($block->input)
                ) {
                    $encoded = json_encode($block->input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    return $encoded === false ? null : $encoded;
                }
            }
            // tool_choice を強制しても稀に自由文で返ることがあるため、text ブロックへフォールバックする。
        }

        $texts = [];
        foreach ($raw->content as $block) {
            if (
                $block instanceof \stdClass
                && isset($block->type) && $block->type === 'text'
                && isset($block->text) && is_string($block->text)
            ) {
                $texts[] = $block->text;
            }
        }

        return $texts === [] ? null : implode('', $texts);
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
            if ($datum instanceof \stdClass && isset($datum->id) && is_string($datum->id) && $datum->id !== '') {
                $models[] = $datum->id;
            }
        }

        return $models;
    }

    /**
     * Anthropic のエラーオブジェクト（{ type, message }）をログ用の配列へ写す。
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
            ],
            static fn($value): bool => $value !== null
        );
    }

    /**
     * Messages API の usage（input_tokens / output_tokens）を {@see TokenUsage} へ写す。
     * Anthropic は total を返さないため合算する。usage が無ければ null。
     */
    private function usageFromResponse(mixed $raw): ?TokenUsage
    {
        if (!$raw instanceof \stdClass || !isset($raw->usage) || !$raw->usage instanceof \stdClass) {
            return null;
        }
        $usage = $raw->usage;
        $input = (int) ($usage->input_tokens ?? 0);
        $output = (int) ($usage->output_tokens ?? 0);

        return new TokenUsage($input, $output, $input + $output);
    }

    /**
     * 認証・バージョンの共通ヘッダー。
     *
     * @return list<string>
     */
    private function baseHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'x-api-key: ' . $this->credentials->apiKey(),
            'anthropic-version: ' . self::API_VERSION,
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
     * Anthropic の API へ GET し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $result = curl_exec($ch);
        if (!is_string($result)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        return $result;
    }

    /**
     * Anthropic の API へ POST し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
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
            CURLOPT_CONNECTTIMEOUT => 10,
            // 重量級モデルの生成は分単位になり得るため長めに取る（無期限ハングだけを防ぐ）。
            CURLOPT_TIMEOUT => 180,
        ]);
        $result = curl_exec($ch);
        if (!is_string($result)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        return $result;
    }

    /**
     * ストリーミング POST。受信バイト列をそのまま $onBytes へ渡す。curl 依存の I/O 境界。
     * SSE のデコードは {@see AnthropicStreamParser} が担い、ここは転送だけを行う。
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
            CURLOPT_CONNECTTIMEOUT => 10,
            // ストリーミングは総時間ではなく「停止」を検出して打ち切る（120秒間 1B/s 未満で中断）。
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 120,
        ]);
        curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
    }
}
