<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\Anthropic;

use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicProvider;
use Acms\Plugins\AI\Tests\Support\FakeConversationStore;
use Acms\Plugins\AI\Tests\Support\StubAnthropicProvider;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * AnthropicProvider がプロバイダ非依存の {@see GenerationRequest} を Messages API のペイロードへ
 * 正しく変換し、応答から {@see \Acms\Plugins\AI\Services\AI\Contracts\GenerationResult} を組み立てる
 * ことを固定する。実通信は {@see StubAnthropicProvider} で差し替える。
 */
final class AnthropicProviderTest extends TestCase
{
    private function provider(?FakeConversationStore $store = null): StubAnthropicProvider
    {
        return new StubAnthropicProvider(new Credentials('sk-ant-test'), $store ?? new FakeConversationStore());
    }

    #[Test]
    #[TestDox('id は anthropic を返す')]
    public function idReturnsAnthropic(): void
    {
        self::assertSame('anthropic', $this->provider()->id());
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
    #[TestDox('API キーがあれば isConfigured は true、無ければ false')]
    public function isConfiguredRequiresApiKeyOnly(): void
    {
        self::assertTrue($this->provider()->isConfigured());
        self::assertFalse((new AnthropicProvider(new Credentials('')))->isConfigured());
    }

    #[Test]
    #[TestDox('fromConfig は ai_anthropic_api_key だけを読む')]
    public function fromConfigReadsAnthropicKey(): void
    {
        $config = new Field();
        $config->set('ai_anthropic_api_key', 'sk-ant-abc');
        $config->set('ai_api_key', 'sk-openai-should-be-ignored');

        $provider = AnthropicProvider::fromConfig($config);

        self::assertTrue($provider->isConfigured());

        $empty = AnthropicProvider::fromConfig(new Field());
        self::assertFalse($empty->isConfigured());
    }

    #[Test]
    #[TestDox('generateText はメッセージ・system 指示を Messages API 形式へ変換して送信する')]
    public function generateTextBuildsMessagesPayload(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = json_encode([
            'content' => [['type' => 'text', 'text' => 'こんにちは']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_UNESCAPED_UNICODE);

        $request = new GenerationRequest(
            'claude-sonnet-5',
            [
                Message::user(ContentPart::text('本文')),
                Message::assistant(ContentPart::text('前回の応答')),
            ],
            'システム指示',
        );
        $result = $provider->generateText($request);

        $payload = $provider->capturedPayload();
        self::assertSame('claude-sonnet-5', $payload['model']);
        self::assertSame('システム指示', $payload['system']);
        self::assertIsInt($payload['max_tokens']);
        self::assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '本文']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '前回の応答']]],
        ], $payload['messages']);
        self::assertArrayNotHasKey('stream', $payload);
        self::assertArrayNotHasKey('tools', $payload);

        self::assertSame('こんにちは', $result->text);
        self::assertSame('end_turn', $result->finishReason);
        self::assertNotNull($result->usage);
        self::assertSame(10, $result->usage->promptTokens);
        self::assertSame(5, $result->usage->completionTokens);
        self::assertSame(15, $result->usage->totalTokens);
        self::assertNull($result->continuationToken);
    }

    #[Test]
    #[TestDox('認証ヘッダーは x-api-key / anthropic-version を送る')]
    public function sendsAnthropicHeaders(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = '{"content":[{"type":"text","text":"ok"}]}';

        $provider->generateText(new GenerationRequest('claude-sonnet-5', [Message::user(ContentPart::text('a'))]));

        $headers = $provider->lastHeaders ?? [];
        self::assertContains('x-api-key: sk-ant-test', $headers);
        self::assertContains('anthropic-version: 2023-06-01', $headers);
    }

    #[Test]
    #[TestDox('outputSchema があると tool use（tools + tool_choice 強制）へ変換する')]
    public function outputSchemaBecomesForcedToolUse(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = json_encode([
            'content' => [
                ['type' => 'tool_use', 'name' => 'items_schema', 'input' => ['items' => [['content' => 'タイトル案']]]],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]];
        $request = new GenerationRequest(
            'claude-sonnet-5',
            [Message::user(ContentPart::text('タイトルを考えて'))],
            null,
            $schema,
            'items_schema',
        );
        $result = $provider->generateText($request);

        $payload = $provider->capturedPayload();
        self::assertSame('items_schema', $payload['tools'][0]['name']);
        self::assertSame($schema, $payload['tools'][0]['input_schema']);
        self::assertSame(['type' => 'tool', 'name' => 'items_schema'], $payload['tool_choice']);

        // tool_use の input が JSON テキストとして返る（OpenAI の json_schema 出力と同じ見え方）。
        self::assertIsString($result->text);
        $decoded = json_decode($result->text, true);
        self::assertSame([['content' => 'タイトル案']], $decoded['items']);
    }

    #[Test]
    #[TestDox('画像パートは URL ソースの image ブロックへ変換する')]
    public function imagePartBecomesUrlImageBlock(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = '{"content":[{"type":"text","text":"猫の写真"}]}';

        $provider->generateText(new GenerationRequest(
            'claude-sonnet-5',
            [Message::user(ContentPart::text('説明して'), ContentPart::image('https://example.com/cat.jpg'))],
        ));

        $payload = $provider->capturedPayload();
        self::assertSame([
            ['type' => 'text', 'text' => '説明して'],
            ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.com/cat.jpg']],
        ], $payload['messages'][0]['content']);
    }

    #[Test]
    #[TestDox('data URL の画像パートは base64 ソースの image ブロックへ変換する')]
    public function dataUrlImagePartBecomesBase64Block(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = '{"content":[{"type":"text","text":"ok"}]}';

        $provider->generateText(new GenerationRequest(
            'claude-sonnet-5',
            [Message::user(ContentPart::image('data:image/png;base64,aW1n'))],
        ));

        $payload = $provider->capturedPayload();
        self::assertSame(
            [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'aW1n']]],
            $payload['messages'][0]['content']
        );
    }

    #[Test]
    #[TestDox('エラー応答は日本語メッセージ付きの失敗結果になる')]
    public function errorResponseBecomesFailureResult(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ]);

        $result = $provider->generateText(
            new GenerationRequest('claude-sonnet-5', [Message::user(ContentPart::text('a'))])
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
        $provider->stubPostResult = '{"content":[{"type":"text","text":"ok"}]}';

        $provider->generateText(new GenerationRequest(
            'claude-sonnet-5',
            [Message::user(ContentPart::text('続きの質問'))],
            null,
            null,
            null,
            $token,
        ));

        $payload = $provider->capturedPayload();
        self::assertCount(3, $payload['messages']);
        self::assertSame('前の質問', $payload['messages'][0]['content'][0]['text']);
        self::assertSame('前の答え', $payload['messages'][1]['content'][0]['text']);
        self::assertSame('続きの質問', $payload['messages'][2]['content'][0]['text']);
    }

    #[Test]
    #[TestDox('streamText は delta を転送し、完了時に会話を保存して継続トークン付き completed を返す')]
    public function streamTextEmitsDeltasAndIssuesContinuationToken(): void
    {
        $store = new FakeConversationStore();
        $provider = $this->provider($store);
        $provider->stubStreamChunks = [
            "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"こん\"}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"にちは\"}}\n\n",
            "data: {\"type\":\"message_stop\"}\n\n",
        ];

        $events = [];
        $provider->streamText(
            new GenerationRequest('claude-sonnet-5', [Message::user(ContentPart::text('挨拶して'))], '指示'),
            static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        // stream フラグが付く。
        $payload = $provider->capturedPayload();
        self::assertTrue($payload['stream']);

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_DELTA, StreamEvent::TYPE_COMPLETED],
            array_map(static fn(StreamEvent $e): string => $e->type, $events)
        );
        self::assertSame('こん', $events[0]->text);

        // completed はストア発行の継続トークンを伴い、履歴（user + assistant）が保存されている。
        $token = $events[2]->continuationToken;
        self::assertIsString($token);
        $history = $store->load($token);
        self::assertCount(2, $history);
        self::assertSame(Message::ROLE_USER, $history[0]->role);
        self::assertSame(Message::ROLE_ASSISTANT, $history[1]->role);
        self::assertSame('こんにちは', $history[1]->parts[0]->value);
    }

    #[Test]
    #[TestDox('ストリーミングで SSE ではない JSON エラーが返ると error イベントへ変換する')]
    public function nonSseErrorBodyBecomesErrorEvent(): void
    {
        $provider = $this->provider();
        $provider->stubStreamChunks = [
            '{"type":"error","error":{"type":"not_found_error","message":"model not found"}}',
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
    #[TestDox('listModels は /v1/models 応答からモデル名を取り出す')]
    public function listModelsParsesResponse(): void
    {
        $provider = $this->provider();
        $provider->stubGetResult = json_encode([
            'data' => [
                ['type' => 'model', 'id' => 'claude-sonnet-5'],
                ['type' => 'model', 'id' => 'claude-haiku-4-5-20251001'],
            ],
        ]);

        self::assertSame(['claude-sonnet-5', 'claude-haiku-4-5-20251001'], $provider->listModels());
    }

    #[Test]
    #[TestDox('listModels は API キー未設定なら通信せず null を返す')]
    public function listModelsReturnsNullWithoutApiKey(): void
    {
        $provider = new StubAnthropicProvider(new Credentials(''));
        $provider->stubGetResult = '{"data":[{"id":"claude-sonnet-5"}]}';

        self::assertNull($provider->listModels());
        self::assertNull($provider->lastUrl);
    }

    #[Test]
    #[TestDox('listModels はエラー応答なら null を返す')]
    public function listModelsReturnsNullOnError(): void
    {
        $provider = $this->provider();
        $provider->stubGetResult = '{"type":"error","error":{"type":"authentication_error","message":"bad key"}}';

        self::assertNull($provider->listModels());
    }
}
