<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Contracts\ManualModelProvider;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Contracts\TokenUsage;
use Acms\Plugins\AI\Services\AI\Logging\ProviderErrorLogContext;
use Acms\Plugins\AI\Services\AI\Conversation\ConversationStore;
use Acms\Plugins\AI\Services\AI\EnvCredential;
use Acms\Plugins\AI\Services\AI\Providers\BoundedResponseBuffer;
use Acms\Plugins\AI\Services\AI\Providers\ResponseSizeLimits;
use Acms\Services\Facades\Logger;
use Field;

/**
 * OpenAI 互換エンドポイント（Chat Completions）向けの {@see AiProvider} 実装。
 *
 * base URL を差し替えて、さくらのAI Engine やセルフホスト環境などの OpenAI 互換 API を利用する。
 * 純正 OpenAI（Responses API）とは別プロバイダとして扱う。互換ワイヤの形状
 * （Bearer 認証、messages/choices、response_format、SSE の [DONE] 終端）は
 * すべてこのクラス配下（本クラスと {@see ChatCompletionsStreamParser} /
 * {@see OpenAiCompatErrorMessage}）に閉じる。
 *
 * 会話継続: Chat Completions はサーバー側に会話状態を持たないため、継続トークンをキーに
 * {@see ConversationStore} で履歴を復元・保存してプロバイダ内部で吸収する
 * （docs/adding-a-provider.md「会話継続トークンの扱い」参照）。
 *
 * 構造化出力: json_schema 対応は互換エンドポイントによりまちまちなので、より広く通る
 * response_format: json_object ＋スキーマをプロンプトへ埋め込む方式を使う。json_object 非対応の
 * エンドポイントではエラーになり得るため、エラーが response_format を原因として示した場合に限り、
 * response_format なしで 1 回だけ再試行する。
 * さらに、モデルが JSON を重複出力する（さくらのAI Engine で観測）・コードフェンスで包む等の
 * 揺れに備え、「最初の完全な JSON オブジェクト」だけを切り出してから返す。
 */
class OpenAiCompatProvider implements AiProvider, ManualModelProvider
{
    public const ID = 'compat';

    /** OpenAI 互換 API キーの正式な環境変数名。 */
    public const ENV_API_KEY = 'ACMS_AI_COMPAT_API_KEY';

    /** 旧PRで案内していた、さくらのAI Engine向け互換エイリアス。 */
    public const ENV_API_KEY_SAKURA = 'ACMS_AI_SAKURA_API_KEY';

    /** base URL 未設定時の既定（さくらのAI Engine）。 */
    public const DEFAULT_BASE_URL = 'https://api.ai.sakura.ad.jp/v1';

    /** Credentials の attributes で base URL を持ち回るキー。 */
    private const ATTR_BASE_URL = 'baseUrl';

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 180;
    private const STREAM_LOW_SPEED_LIMIT = 1;
    private const STREAM_LOW_SPEED_TIME = 120;

    private readonly string $baseUrl;
    private readonly OutboundEndpointGuard $endpointGuard;

    public function __construct(
        private readonly Credentials $credentials,
        private readonly ?ConversationStore $conversations = null,
        ?OutboundEndpointGuard $endpointGuard = null,
    ) {
        $this->baseUrl = $this->normalizeBaseUrl($this->credentials->attribute(self::ATTR_BASE_URL));
        $this->endpointGuard = $endpointGuard ?? new OutboundEndpointGuard();
    }

    /**
     * config（`ai_compat_api_key` / `ai_compat_base_url`）から生成する。APIキーは環境変数を優先する。
     * base URL が空なら既定（さくらのAI Engine）を使う。モデルはリクエストごとに与えられる。
     */
    public static function fromConfig(Field $config): self
    {
        $baseUrl = $config->get('ai_compat_base_url');
        if (trim($baseUrl) === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        return new self(new Credentials(
            EnvCredential::getForConfig('ai_compat_api_key', $config->get('ai_compat_api_key')),
            [self::ATTR_BASE_URL => $baseUrl]
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
        return $this->credentials->apiKey() !== '' && $this->baseUrl !== '';
    }

    public function generateText(GenerationRequest $request): GenerationResult
    {
        $structured = $request->outputSchema !== null;
        $payload = $this->buildPayload($request, false);

        if ($structured) {
            // 広く通る json_object モードで要求し、非対応エンドポイントなら
            // response_format なしで 1 回だけ再試行する。
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $raw = $this->post($payload);
        if (
            $structured
            && $raw instanceof \stdClass
            && isset($raw->error)
            && $this->isResponseFormatUnsupported($raw->error)
        ) {
            unset($payload['response_format']);
            $raw = $this->post($payload);
        }

        if (!$raw instanceof \stdClass) {
            Logger::error('【AI plugin】 OpenAI互換 API の応答を解析できませんでした', [
                'model' => $request->model,
                'jsonError' => json_last_error_msg(),
            ]);
            return new GenerationResult(
                null,
                $raw,
                errorMessage: 'AI からの応答を解析できませんでした。時間をおいて再試行してください。'
            );
        }

        if (isset($raw->error)) {
            Logger::error(
                '【AI plugin】 OpenAI互換 API がエラーを返しました',
                ProviderErrorLogContext::from($raw->error)
            );
            return new GenerationResult(null, $raw, errorMessage: OpenAiCompatErrorMessage::fromError($raw->error));
        }

        $text = $this->extractText($raw);
        if ($text !== null) {
            ResponseSizeLimits::assertGeneratedText($text);
        }
        if ($structured && $text !== null) {
            // 重複出力・コードフェンス・末尾ゴミがあっても最初の完全な JSON だけを返す。
            $text = $this->isolateJson($text);
        }
        $finishReason = $this->finishReason($raw);

        if ($structured && $finishReason !== null && $finishReason !== 'stop') {
            Logger::warning('【AI plugin】 OpenAI互換 API が構造化出力を完了できませんでした', [
                'model' => $request->model,
                'finishReason' => $finishReason,
            ]);
            return new GenerationResult(
                null,
                $raw,
                null,
                $finishReason,
                $this->usageFromResponse($raw),
                $finishReason === 'length'
                    ? 'AI の応答が出力上限に達しました。内容を短くして再試行してください。'
                    : 'AI が構造化出力を完了できませんでした。入力内容を見直して再試行してください。'
            );
        }

        if ($structured && $text !== null) {
            $decoded = json_decode($text);
            if (!$decoded instanceof \stdClass) {
                Logger::warning('【AI plugin】 OpenAI互換 API が有効な JSON オブジェクトを返しませんでした', [
                    'model' => $request->model,
                    'finishReason' => $finishReason,
                    'jsonError' => json_last_error_msg(),
                ]);
                return new GenerationResult(
                    null,
                    $raw,
                    null,
                    $finishReason,
                    $this->usageFromResponse($raw),
                    'AI から有効な形式のデータを取得できませんでした。再試行してください。'
                );
            }
        }

        // エラーではないが本文が取れないケース。原因切り分けのためモデルと終了理由を残す。
        if ($text === null || $text === '') {
            Logger::warning('【AI plugin】 OpenAI互換 API から本文を取得できませんでした', [
                'model' => $request->model,
                'finishReason' => $finishReason,
            ]);
            return new GenerationResult(
                null,
                $raw,
                null,
                $finishReason,
                $this->usageFromResponse($raw),
                'AI から本文を取得できませんでした。時間をおいて再試行してください。'
            );
        }

        // 継続トークンは返さない。単発生成（タイトル/タグ）に会話状態は不要で、
        // チャットの継続は streamText() 側が会話ストアで発行する。
        return new GenerationResult($text, $raw, null, $finishReason, $this->usageFromResponse($raw));
    }

    public function streamText(GenerationRequest $request, callable $onEvent): void
    {
        $messages = $this->conversationStore()->messagesForRequest($request);
        $payload = $this->buildPayload($request, true, $messages);

        $assistantText = '';
        $rawBytes = '';
        $sawAnyEvent = false;
        $sawTerminalEvent = false;
        $parser = new ChatCompletionsStreamParser();

        $this->httpPostStream(
            $this->baseUrl . '/chat/completions',
            $this->baseHeaders(),
            $this->encode($payload),
            function (string $bytes) use ($parser, $onEvent, &$assistantText, &$rawBytes, &$sawAnyEvent, &$sawTerminalEvent, $request, $messages): void {
                if (!$sawAnyEvent) {
                    $rawBytes .= $bytes;
                }
                $parser->feed($bytes, function (StreamEvent $event) use ($onEvent, &$assistantText, &$rawBytes, &$sawAnyEvent, &$sawTerminalEvent, $request, $messages): void {
                    if (!$sawAnyEvent) {
                        $rawBytes = '';
                    }
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

        // リクエスト不正（モデル名誤り等）のとき互換エンドポイントは SSE ではなく素の JSON エラーを返す。
        // その場合はイベントが 1 つも出ないため、受信全体をエラーとして解釈しフロントへ通知する。
        if (!$sawTerminalEvent) {
            $decoded = json_decode($rawBytes);
            $error = ($decoded instanceof \stdClass && isset($decoded->error)) ? $decoded->error : null;
            if ($error !== null) {
                Logger::error(
                    '【AI plugin】 OpenAI互換 API がエラーを返しました',
                    ProviderErrorLogContext::from($error)
                );
            }
            $onEvent(StreamEvent::error(
                $error !== null
                    ? OpenAiCompatErrorMessage::fromError($error)
                    : 'AI からのストリーミング応答が途中で終了しました。再試行してください。'
            ));
        }
    }

    /**
     * Chat Completions のリクエストペイロードを組み立てる。
     *
     * @param list<Message>|null $messages 送信するメッセージ列（null なら $request->messages）
     * @return array<string, mixed>
     */
    private function buildPayload(GenerationRequest $request, bool $stream, ?array $messages = null): array
    {
        if ($messages === null) {
            $messages = $this->conversationStore()->messagesForRequest($request);
        }

        $apiMessages = [];
        $system = $this->buildSystemPrompt($request);
        if ($system !== null) {
            $apiMessages[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $message) {
            $apiMessages[] = [
                'role' => $message->role,
                'content' => $this->buildContent($message),
            ];
        }

        $payload = [
            'model' => $request->model,
            'messages' => $apiMessages,
        ];
        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * system プロンプトを組み立てる。構造化出力時はスキーマ準拠の JSON だけを返すよう指示を足す
     * （json_schema の response_format は互換エンドポイントの対応がまちまちなため、
     * プロンプト指示＋json_object＋救済パースの組み合わせで確度を上げる）。
     */
    private function buildSystemPrompt(GenerationRequest $request): ?string
    {
        $system = $request->instructions;
        if ($request->outputSchema === null) {
            return $system;
        }

        $schemaJson = json_encode($request->outputSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $instruction =
            "\n\n## Output format (strict)\n" .
            "Respond with exactly one JSON object that conforms to the following JSON Schema.\n" .
            "Output the JSON object only: no code fences, no explanations, " .
            "and never repeat or duplicate the object.\n" .
            ($schemaJson === false ? '' : $schemaJson);

        return ($system ?? '') . $instruction;
    }

    /**
     * 1 メッセージ分のコンテンツ断片を Chat Completions の content へ変換する。
     * テキストのみなら文字列、画像を含む場合はマルチモーダル配列（image_url）にする。
     *
     * @return string|list<array<string, mixed>>
     */
    private function buildContent(Message $message): string|array
    {
        $hasImage = false;
        foreach ($message->parts as $part) {
            if (in_array($part->type, [ContentPart::TYPE_IMAGE, ContentPart::TYPE_IMAGE_DATA], true)) {
                $hasImage = true;
                break;
            }
        }

        if (!$hasImage) {
            $texts = [];
            foreach ($message->parts as $part) {
                $texts[] = $part->value;
            }
            return implode("\n", $texts);
        }

        $contents = [];
        foreach ($message->parts as $part) {
            if ($part->type === ContentPart::TYPE_IMAGE_DATA) {
                $contents[] = ['type' => 'image_url', 'image_url' => ['url' => $part->asDataUrl()]];
            } elseif ($part->type === ContentPart::TYPE_IMAGE) {
                $contents[] = ['type' => 'image_url', 'image_url' => ['url' => $part->value]];
            } else {
                $contents[] = ['type' => 'text', 'text' => $part->value];
            }
        }

        return $contents;
    }

    /**
     * 応答の choices[0].message.content から本文を取り出す。
     */
    private function extractText(mixed $raw): ?string
    {
        if (
            !$raw instanceof \stdClass
            || !isset($raw->choices)
            || !is_array($raw->choices)
            || $raw->choices === []
        ) {
            return null;
        }
        $choice = $raw->choices[0];
        if (
            !$choice instanceof \stdClass
            || !isset($choice->message)
            || !$choice->message instanceof \stdClass
            || !isset($choice->message->content)
            || !is_string($choice->message->content)
        ) {
            return null;
        }

        return $choice->message->content;
    }

    private function finishReason(mixed $raw): ?string
    {
        if (
            !$raw instanceof \stdClass
            || !isset($raw->choices)
            || !is_array($raw->choices)
            || $raw->choices === []
        ) {
            return null;
        }
        $choice = $raw->choices[0];
        if (!$choice instanceof \stdClass || !isset($choice->finish_reason) || !is_string($choice->finish_reason)) {
            return null;
        }

        return $choice->finish_reason;
    }

    /**
     * 応答テキストから「最初の完全な JSON オブジェクト」だけを切り出す。
     *
     * モデルが JSON を重複出力する（さくらのAI Engine で観測。例: {"items":[...]} を 2 回返す）
     * ことがあり、単純に「最初の { 〜 最後の }」で切り出すと連結で json_decode が失敗する。
     * 文字列・エスケープを考慮して走査し、最初に閉じたオブジェクトだけを返す。
     * コードフェンス（```json 〜 ```）も除去する。
     */
    private function isolateJson(string $rawText): string
    {
        $text = trim($rawText);

        $text = preg_replace('/\A```[a-zA-Z]*\s*/', '', $text);
        $text = preg_replace('/\s*```\z/', '', $text ?? '');
        $text = $text ?? '';

        $start = strpos($text, '{');
        if ($start === false) {
            return $text;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    // 最初の完全なオブジェクトを返す（以降の重複・余分は捨てる）。
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // 閉じ切らなかった場合は従来どおり最後の } までで救済する。
        $end = strrpos($text, '}');
        if ($end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    /** response_format だけが非対応と確認できる場合に限り、安全な 1 回だけの再試行を許可する。 */
    private function isResponseFormatUnsupported(mixed $error): bool
    {
        if (!$error instanceof \stdClass) {
            return false;
        }

        $param = isset($error->param) && is_string($error->param) ? strtolower($error->param) : '';
        if ($param === 'response_format') {
            return true;
        }

        $message = isset($error->message) && is_string($error->message) ? strtolower($error->message) : '';

        return str_contains($message, 'response_format')
            || str_contains($message, 'json_object')
            || str_contains($message, 'json mode');
    }

    /**
     * usage（prompt_tokens / completion_tokens / total_tokens）を {@see TokenUsage} へ写す。
     * usage が無ければ null。
     */
    private function usageFromResponse(mixed $raw): ?TokenUsage
    {
        if (!$raw instanceof \stdClass || !isset($raw->usage) || !$raw->usage instanceof \stdClass) {
            return null;
        }
        $usage = $raw->usage;

        return new TokenUsage(
            (int) ($usage->prompt_tokens ?? 0),
            (int) ($usage->completion_tokens ?? 0),
            (int) ($usage->total_tokens ?? 0),
        );
    }

    /**
     * base URL の正規化と安全性検証。
     * https 以外・認証情報入り URL・不正な形式は受け付けず、空文字を返して
     * isConfigured() を false にする（理由はログへ残し、管理者が気づけるようにする）。
     */
    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            Logger::warning('【AI plugin】 OpenAI互換エンドポイントの URL が不正です', ['baseUrl' => $baseUrl]);
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            Logger::warning('【AI plugin】 OpenAI互換エンドポイント URL に認証情報は含められません', []);
            return '';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            Logger::warning('【AI plugin】 OpenAI互換エンドポイント URL にクエリやフラグメントは含められません', []);
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https') {
            Logger::warning('【AI plugin】 OpenAI互換エンドポイントは https を指定してください', ['baseUrl' => $baseUrl]);
            return '';
        }

        return $baseUrl;
    }

    /**
     * 認証ヘッダー（Bearer）。
     *
     * @return list<string>
     */
    private function baseHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->credentials->apiKey(),
        ];
    }

    /**
     * /chat/completions へ POST し、応答を json_decode して返す。
     *
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): mixed
    {
        return json_decode(
            $this->httpPostJson($this->baseUrl . '/chat/completions', $this->baseHeaders(), $this->encode($payload))
        );
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
     * 互換エンドポイントへ POST し、レスポンスボディ（JSON 文字列）を返す。curl 依存の I/O 境界。
     * テストではこのメソッドを差し替えてリクエスト変換・レスポンス解析を検証する。
     *
     * @param list<string> $headers
     * @throws \Exception cURL 実行に失敗した場合
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function httpPostJson(string $url, array $headers, string $body): string
    {
        $resolve = $this->endpointGuard->resolveForCurl($url);
        $ch = curl_init();
        $buffer = new BoundedResponseBuffer();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => static fn($_ch, string $bytes): int => $buffer->append($bytes),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            // プロキシ側のDNS解決で検査済みIPの固定を迂回されないよう、環境変数のプロキシを無効化する。
            CURLOPT_PROXY => '',
        ];
        if ($resolve !== []) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }
        if (!curl_setopt_array($ch, $options)) {
            throw new \RuntimeException('安全なcURLオプションを設定できませんでした。');
        }
        curl_exec($ch);
        if (curl_errno($ch) !== 0) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        return $buffer->body();
    }

    /**
     * ストリーミング POST。受信バイト列をそのまま $onBytes へ渡す。curl 依存の I/O 境界。
     * SSE のデコードは {@see ChatCompletionsStreamParser} が担い、ここは転送だけを行う。
     * テストではこのメソッドを差し替えて、ワイヤ列（SSE バイト列）を直接注入する。
     *
     * @param list<string> $headers
     * @param callable(string): void $onBytes
     * @throws \Exception cURL 実行に失敗した場合
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function httpPostStream(string $url, array $headers, string $body, callable $onBytes): void
    {
        $resolve = $this->endpointGuard->resolveForCurl($url);
        $ch = curl_init();
        $options = [
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
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            // プロキシ側のDNS解決で検査済みIPの固定を迂回されないよう、環境変数のプロキシを無効化する。
            CURLOPT_PROXY => '',
        ];
        if ($resolve !== []) {
            $options[CURLOPT_RESOLVE] = $resolve;
        }
        if (!curl_setopt_array($ch, $options)) {
            throw new \RuntimeException('安全なcURLオプションを設定できませんでした。');
        }
        curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
    }
}
