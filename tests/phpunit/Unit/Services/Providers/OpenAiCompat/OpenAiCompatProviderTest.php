<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatProvider;
use Acms\Plugins\AI\Tests\Support\FakeConversationStore;
use Acms\Plugins\AI\Tests\Support\StubOpenAiCompatProvider;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAiCompatProvider がプロバイダ非依存の {@see GenerationRequest} を Chat Completions の
 * ペイロードへ正しく変換し、応答から {@see \Acms\Plugins\AI\Services\AI\Contracts\GenerationResult} を
 * 組み立てることを固定する。実通信は {@see StubOpenAiCompatProvider} で差し替える。
 */
final class OpenAiCompatProviderTest extends TestCase
{
    private function provider(
        ?FakeConversationStore $store = null,
        string $baseUrl = 'https://api.ai.sakura.ad.jp/v1'
    ): StubOpenAiCompatProvider {
        return new StubOpenAiCompatProvider(
            new Credentials('sk-compat-test', ['baseUrl' => $baseUrl]),
            $store ?? new FakeConversationStore()
        );
    }

    #[Test]
    #[TestDox('id は compat を返す')]
    public function idReturnsCompat(): void
    {
        self::assertSame('compat', $this->provider()->id());
    }

    #[Test]
    #[TestDox('テキスト生成・構造化出力・画像入力・ストリーミングのすべてに対応する')]
    public function supportsAllCapabilities(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports(Capability::TextGeneration));
        self::assertTrue($provider->supports(Capability::StructuredOutput));
        self::assertTrue($provider->supports(Capability::VisionInput));
        self::assertTrue($provider->supports(Capability::Streaming));
    }

    #[Test]
    #[TestDox('fromConfig は ai_compat_api_key / ai_compat_base_url を読み、URL 空欄はさくらの既定を使う')]
    public function fromConfigReadsCompatKeysWithSakuraDefault(): void
    {
        $config = new Field();
        $config->set('ai_compat_api_key', 'sk-abc');

        $provider = OpenAiCompatProvider::fromConfig($config);
        self::assertTrue($provider->isConfigured());

        self::assertFalse(OpenAiCompatProvider::fromConfig(new Field())->isConfigured());
    }

    #[Test]
    #[TestDox('base URL は https 以外（ループバック除く）・認証情報入り・不正形式を拒否する')]
    public function baseUrlValidationRejectsUnsafeUrls(): void
    {
        $reject = static fn(string $url): bool => (new StubOpenAiCompatProvider(
            new Credentials('sk', ['baseUrl' => $url])
        ))->isConfigured();

        self::assertFalse($reject('http://example.com/v1'));
        self::assertFalse($reject('ftp://example.com/v1'));
        self::assertFalse($reject('https://user:pass@example.com/v1'));
        self::assertFalse($reject('not a url'));

        self::assertTrue($reject('https://api.ai.sakura.ad.jp/v1'));
        self::assertTrue($reject('http://localhost:1234/v1'));
        self::assertTrue($reject('http://127.0.0.1:8080/v1'));
    }

    #[Test]
    #[TestDox('generateText はメッセージ・system 指示を Chat Completions 形式へ変換して送信する')]
    public function generateTextBuildsMessagesPayload(): void
    {
        $provider = $this->provider();
        $provider->stubPostResults = [json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'こんにちは'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
        ], JSON_UNESCAPED_UNICODE)
        ];

        $request = new GenerationRequest(
            'gpt-oss-120b',
            [
                Message::user(ContentPart::text('本文')),
                Message::assistant(ContentPart::text('前回の応答')),
            ],
            'システム指示',
        );
        $result = $provider->generateText($request);

        self::assertSame('https://api.ai.sakura.ad.jp/v1/chat/completions', $provider->lastUrl);
        self::assertContains('Authorization: Bearer sk-compat-test', $provider->lastHeaders ?? []);

        $payload = $provider->capturedPayload();
        self::assertSame('gpt-oss-120b', $payload['model']);
        self::assertSame([
            ['role' => 'system', 'content' => 'システム指示'],
            ['role' => 'user', 'content' => '本文'],
            ['role' => 'assistant', 'content' => '前回の応答'],
        ], $payload['messages']);
        self::assertArrayNotHasKey('response_format', $payload);
        self::assertArrayNotHasKey('stream', $payload);

        self::assertSame('こんにちは', $result->text);
        self::assertSame('stop', $result->finishReason);
        self::assertNotNull($result->usage);
        self::assertSame(10, $result->usage->totalTokens);
        self::assertNull($result->continuationToken);
    }

    #[Test]
    #[TestDox('outputSchema があると json_object モード＋スキーマ入りプロンプト指示になる')]
    public function outputSchemaUsesJsonObjectModeAndPromptInstruction(): void
    {
        $provider = $this->provider();
        $provider->stubPostResults = [
            '{"choices":[{"message":{"content":"{\"items\":[{\"content\":\"案\"}]}"}}]}',
        ];

        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]];
        $provider->generateText(new GenerationRequest(
            'gpt-oss-120b',
            [Message::user(ContentPart::text('タイトルを考えて'))],
            'システム指示',
            $schema,
            'items_schema',
        ));

        $payload = $provider->capturedPayload();
        self::assertSame(['type' => 'json_object'], $payload['response_format']);
        // system プロンプトにスキーマと「重複出力禁止」の指示が埋め込まれる。
        $system = $payload['messages'][0];
        self::assertSame('system', $system['role']);
        self::assertStringContainsString('システム指示', $system['content']);
        self::assertStringContainsString('JSON Schema', $system['content']);
        self::assertStringContainsString('"items"', $system['content']);
    }

    #[Test]
    #[TestDox('json_object 非対応エンドポイントでは response_format なしで 1 回だけ再試行する')]
    public function retriesWithoutResponseFormatWhenUnsupported(): void
    {
        $provider = $this->provider();
        $provider->stubPostResults = [
            '{"error":{"message":"response_format is not supported","type":"invalid_request_error"}}',
            '{"choices":[{"message":{"content":"{\"items\":[]}"}}]}',
        ];

        $result = $provider->generateText(new GenerationRequest(
            'local-model',
            [Message::user(ContentPart::text('a'))],
            null,
            ['type' => 'object'],
        ));

        self::assertCount(2, $provider->postBodies);
        self::assertSame(['type' => 'json_object'], $provider->capturedPayload(0)['response_format']);
        self::assertArrayNotHasKey('response_format', $provider->capturedPayload(1));
        self::assertSame('{"items":[]}', $result->text);
    }

    #[Test]
    #[TestDox('構造化出力の応答から最初の完全な JSON だけを切り出す（重複出力・コードフェンス対策）')]
    public function isolatesFirstCompleteJsonObject(): void
    {
        $provider = $this->provider();
        // さくらで観測された「JSON を 2 回返す」応答＋コードフェンスを再現する。
        $duplicated = "```json\n{\"items\":[{\"content\":\"A{B}\"}]}\n{\"items\":[{\"content\":\"重複\"}]}\n```";
        $provider->stubPostResults = [json_encode([
            'choices' => [['message' => ['content' => $duplicated]]],
        ], JSON_UNESCAPED_UNICODE)
        ];

        $result = $provider->generateText(new GenerationRequest(
            'gpt-oss-120b',
            [Message::user(ContentPart::text('a'))],
            null,
            ['type' => 'object'],
        ));

        self::assertSame('{"items":[{"content":"A{B}"}]}', $result->text);
        $decoded = json_decode($result->text, true);
        self::assertSame([['content' => 'A{B}']], $decoded['items']);
    }

    #[Test]
    #[TestDox('エラー応答は日本語メッセージ付きの失敗結果になる')]
    public function errorResponseBecomesFailureResult(): void
    {
        $provider = $this->provider();
        $provider->stubPostResults = [
            '{"error":{"message":"Incorrect API key","type":"authentication_error","code":"invalid_api_key"}}',
        ];

        $result = $provider->generateText(
            new GenerationRequest('gpt-oss-120b', [Message::user(ContentPart::text('a'))])
        );

        self::assertNull($result->text);
        self::assertIsString($result->errorMessage);
        self::assertStringContainsString('API キー', $result->errorMessage);
    }

    #[Test]
    #[TestDox('継続トークンがあると会話ストアの履歴を先頭へ復元して送信する')]
    public function continuationTokenRestoresHistory(): void
    {
        $store = new FakeConversationStore();
        $token = $store->save(null, [
            Message::user(ContentPart::text('前の質問')),
            Message::assistant(ContentPart::text('前の答え')),
        ]);

        $provider = $this->provider($store);
        $provider->stubPostResults = ['{"choices":[{"message":{"content":"ok"}}]}'];

        $provider->generateText(new GenerationRequest(
            'gpt-oss-120b',
            [Message::user(ContentPart::text('続きの質問'))],
            null,
            null,
            null,
            $token,
        ));

        $messages = $provider->capturedPayload()['messages'];
        self::assertSame(
            [
                ['role' => 'user', 'content' => '前の質問'],
                ['role' => 'assistant', 'content' => '前の答え'],
                ['role' => 'user', 'content' => '続きの質問'],
            ],
            $messages
        );
    }

    #[Test]
    #[TestDox('streamText は delta を転送し、完了時に会話を保存して継続トークン付き completed を返す')]
    public function streamTextEmitsDeltasAndIssuesContinuationToken(): void
    {
        $store = new FakeConversationStore();
        $provider = $this->provider($store);
        $provider->stubStreamChunks = [
            "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"こん\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"にちは\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n",
        ];

        $events = [];
        $provider->streamText(
            new GenerationRequest('gpt-oss-120b', [Message::user(ContentPart::text('挨拶して'))], '指示'),
            static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $payload = $provider->capturedPayload();
        self::assertTrue($payload['stream']);

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_DELTA, StreamEvent::TYPE_COMPLETED],
            array_map(static fn(StreamEvent $e): string => $e->type, $events)
        );

        $token = $events[2]->continuationToken;
        self::assertIsString($token);
        $history = $store->load($token);
        self::assertCount(2, $history);
        self::assertSame('こんにちは', $history[1]->parts[0]->value);
    }

    #[Test]
    #[TestDox('ストリーミングで SSE ではない JSON エラーが返ると error イベントへ変換する')]
    public function nonSseErrorBodyBecomesErrorEvent(): void
    {
        $provider = $this->provider();
        $provider->stubStreamChunks = [
            '{"error":{"message":"The model does not exist","type":"invalid_request_error","code":"model_not_found"}}',
        ];

        $events = [];
        $provider->streamText(
            new GenerationRequest('bad-model', [Message::user(ContentPart::text('a'))]),
            static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_ERROR, $events[0]->type);
        self::assertIsString($events[0]->message);
        self::assertStringContainsString('モデル', $events[0]->message);
    }

    #[Test]
    #[TestDox('listModels は /models 応答からモデル名を取り出す')]
    public function listModelsParsesResponse(): void
    {
        $provider = $this->provider();
        $provider->stubGetResult = '{"data":[{"id":"gpt-oss-120b"},{"id":"qwen3-coder-480b"}]}';

        self::assertSame(['gpt-oss-120b', 'qwen3-coder-480b'], $provider->listModels());
        self::assertSame('https://api.ai.sakura.ad.jp/v1/models', $provider->lastUrl);
    }

    #[Test]
    #[TestDox('listModels は認証情報未充足なら通信せず null、エラー応答でも null を返す')]
    public function listModelsReturnsNullWithoutCredentialsOrOnError(): void
    {
        $unconfigured = new StubOpenAiCompatProvider(new Credentials('', ['baseUrl' => '']));
        self::assertNull($unconfigured->listModels());
        self::assertNull($unconfigured->lastUrl);

        $provider = $this->provider();
        $provider->stubGetResult = '{"error":{"message":"bad key","type":"authentication_error"}}';
        self::assertNull($provider->listModels());
    }
}
