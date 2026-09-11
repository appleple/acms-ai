<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\Gemini;

use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiProvider;
use Acms\Plugins\AI\Tests\Support\FakeConversationStore;
use Acms\Plugins\AI\Tests\Support\StubGeminiProvider;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * GeminiProvider がプロバイダ非依存の {@see GenerationRequest} を generateContent のペイロードへ
 * 正しく変換し、応答から {@see \Acms\Plugins\AI\Services\AI\Contracts\GenerationResult} を組み立てる
 * ことを固定する。実通信は {@see StubGeminiProvider} で差し替える。
 */
final class GeminiProviderTest extends TestCase
{
    private function provider(?FakeConversationStore $store = null): StubGeminiProvider
    {
        return new StubGeminiProvider(new Credentials('AIza-test'), $store ?? new FakeConversationStore());
    }

    #[Test]
    #[TestDox('id は gemini を返す')]
    public function idReturnsGemini(): void
    {
        self::assertSame('gemini', $this->provider()->id());
    }

    #[Test]
    #[TestDox('テキスト生成・構造化出力・信頼済み画像入力・ストリーミングに対応する')]
    public function supportsAllCapabilities(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports(Capability::TextGeneration));
        self::assertTrue($provider->supports(Capability::StructuredOutput));
        self::assertTrue($provider->supports(Capability::VisionInput));
        self::assertTrue($provider->supports(Capability::Streaming));
    }

    #[Test]
    #[TestDox('fromConfig は ai_gemini_api_key だけを読む')]
    public function fromConfigReadsGeminiKey(): void
    {
        $config = new Field();
        $config->set('ai_gemini_api_key', 'AIza-abc');

        self::assertTrue(GeminiProvider::fromConfig($config)->isConfigured());
        self::assertFalse(GeminiProvider::fromConfig(new Field())->isConfigured());
    }

    #[Test]
    #[TestDox('generateText はメッセージ・system 指示を generateContent 形式へ変換して送信する')]
    public function generateTextBuildsContentsPayload(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = json_encode([
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => 'こんにちは']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 8, 'candidatesTokenCount' => 4, 'totalTokenCount' => 12],
        ], JSON_UNESCAPED_UNICODE);

        $request = new GenerationRequest(
            'gemini-2.5-flash',
            [
                Message::user(ContentPart::text('本文')),
                Message::assistant(ContentPart::text('前回の応答')),
            ],
            'システム指示',
        );
        $result = $provider->generateText($request);

        // モデルは URL パスに入る。認証はヘッダーで送りクエリにキーを含めない。
        self::assertIsString($provider->lastUrl);
        self::assertStringContainsString('/models/gemini-2.5-flash:generateContent', $provider->lastUrl);
        self::assertStringNotContainsString('key=', $provider->lastUrl);
        self::assertContains('x-goog-api-key: AIza-test', $provider->lastHeaders ?? []);

        $payload = $provider->capturedPayload();
        self::assertSame([['text' => 'システム指示']], $payload['systemInstruction']['parts']);
        // assistant ロールは model へ写す。
        self::assertSame([
            ['role' => 'user', 'parts' => [['text' => '本文']]],
            ['role' => 'model', 'parts' => [['text' => '前回の応答']]],
        ], $payload['contents']);

        self::assertSame('こんにちは', $result->text);
        self::assertSame('STOP', $result->finishReason);
        self::assertNotNull($result->usage);
        self::assertSame(8, $result->usage->promptTokens);
        self::assertSame(4, $result->usage->completionTokens);
        self::assertSame(12, $result->usage->totalTokens);
        self::assertNull($result->continuationToken);
    }

    #[Test]
    #[TestDox('outputSchema は制約を保持した responseJsonSchema として送信する')]
    public function outputSchemaBecomesResponseJsonSchema(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = json_encode([
            'candidates' => [
                ['content' => ['parts' => [['text' => '{"items":[{"content":"タイトル案"}]}']]]],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['content' => ['type' => 'string']],
                        'required' => ['content'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
        $result = $provider->generateText(new GenerationRequest(
            'gemini-2.5-flash',
            [Message::user(ContentPart::text('タイトルを考えて'))],
            null,
            $schema,
            'items_schema',
        ));

        $payload = $provider->capturedPayload();
        $config = $payload['generationConfig'];
        self::assertSame('application/json', $config['responseMimeType']);
        self::assertSame($schema, $config['responseJsonSchema']);
        self::assertArrayNotHasKey('responseSchema', $config);

        // 応答テキストは JSON 文字列のまま返る（消費側が decode する）。
        self::assertIsString($result->text);
        $decoded = json_decode($result->text, true);
        self::assertSame([['content' => 'タイトル案']], $decoded['items']);
    }

    #[Test]
    #[TestDox('画像 URL は外部取得せず拒否する')]
    public function imagePartIsRejected(): void
    {
        $provider = $this->provider();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('画像 URL 入力に対応していません');

        $provider->generateText(new GenerationRequest(
            'gemini-2.5-flash',
            [Message::user(ContentPart::text('説明して'), ContentPart::image('https://example.com/cat.png'))],
        ));
    }

    #[Test]
    #[TestDox('信頼済み画像データは inlineData へ変換する')]
    public function imageDataPartBecomesInlineData(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = '{"candidates":[{"content":{"parts":[{"text":"猫"}]}}]}';

        $provider->generateText(new GenerationRequest(
            'gemini-2.5-flash',
            [Message::user(ContentPart::text('説明して'), ContentPart::imageData('image/png', 'YWJj'))],
        ));

        self::assertSame(
            ['inlineData' => ['mimeType' => 'image/png', 'data' => 'YWJj']],
            $provider->capturedPayload()['contents'][0]['parts'][1]
        );
    }

    #[Test]
    #[TestDox('安全性でブロックされた応答は本文なしの失敗結果になる')]
    public function safetyBlockedResponseBecomesFailureResult(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = '{"promptFeedback":{"blockReason":"SAFETY"}}';

        $result = $provider->generateText(
            new GenerationRequest('gemini-2.5-flash', [Message::user(ContentPart::text('a'))])
        );

        self::assertNull($result->text);
        self::assertIsString($result->errorMessage);
        self::assertStringContainsString('ブロック', $result->errorMessage);
    }

    #[Test]
    #[TestDox('出力上限で終了した構造化応答は不完全な本文を返さず失敗結果になる')]
    public function maxTokensResponseBecomesFailureResult(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = '{"candidates":[{"content":{"parts":[{"text":"{\\"items\\":["}]},"finishReason":"MAX_TOKENS"}]}';

        $result = $provider->generateText(
            new GenerationRequest('gemini-2.5-flash', [Message::user(ContentPart::text('a'))])
        );

        self::assertNull($result->text);
        self::assertSame('MAX_TOKENS', $result->finishReason);
        self::assertIsString($result->errorMessage);
        self::assertStringContainsString('出力上限', $result->errorMessage);
    }

    #[Test]
    #[TestDox('エラー応答は日本語メッセージ付きの失敗結果になる')]
    public function errorResponseBecomesFailureResult(): void
    {
        $provider = $this->provider();
        $provider->stubPostResult = json_encode([
            'error' => ['code' => 400, 'message' => 'API key not valid.', 'status' => 'INVALID_ARGUMENT'],
        ]);

        $result = $provider->generateText(
            new GenerationRequest('gemini-2.5-flash', [Message::user(ContentPart::text('a'))])
        );

        self::assertNull($result->text);
        self::assertIsString($result->errorMessage);
        self::assertStringContainsString('不正', $result->errorMessage);
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
        $provider->stubPostResult = '{"candidates":[{"content":{"parts":[{"text":"ok"}]}}]}';

        $provider->generateText(new GenerationRequest(
            'gemini-2.5-flash',
            [Message::user(ContentPart::text('続きの質問'))],
            null,
            null,
            null,
            $token,
        ));

        $payload = $provider->capturedPayload();
        self::assertCount(3, $payload['contents']);
        self::assertSame('user', $payload['contents'][0]['role']);
        self::assertSame('model', $payload['contents'][1]['role']);
        self::assertSame('続きの質問', $payload['contents'][2]['parts'][0]['text']);
    }

    #[Test]
    #[TestDox('streamText は delta を転送し、完了時に会話を保存して継続トークン付き completed を返す')]
    public function streamTextEmitsDeltasAndIssuesContinuationToken(): void
    {
        $store = new FakeConversationStore();
        $provider = $this->provider($store);
        $provider->stubStreamChunks = [
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"こん\"}],\"role\":\"model\"}}]}\r\n\r\n",
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"にちは\"}],\"role\":\"model\"},\"finishReason\":\"STOP\"}]}\r\n\r\n",
        ];

        $events = [];
        $provider->streamText(
            new GenerationRequest('gemini-2.5-flash', [Message::user(ContentPart::text('挨拶して'))], '指示'),
            static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertIsString($provider->lastUrl);
        self::assertStringContainsString(':streamGenerateContent?alt=sse', $provider->lastUrl);

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
            '{"error":{"code":404,"message":"model not found","status":"NOT_FOUND"}}',
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
    #[TestDox('本文なしの STOP は error を返し、空の応答を会話履歴へ保存しない')]
    public function emptyCompletedStreamBecomesError(): void
    {
        $provider = $this->provider();
        $provider->stubStreamChunks = [
            "data: {\"candidates\":[{\"content\":{\"parts\":[]},\"finishReason\":\"STOP\"}]}\n\n",
        ];

        $events = [];
        $provider->streamText(
            new GenerationRequest('gemini-2.5-flash', [Message::user(ContentPart::text('a'))]),
            static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_ERROR, $events[0]->type);
        self::assertIsString($events[0]->message);
        self::assertStringContainsString('本文', $events[0]->message);
    }

    #[Test]
    #[TestDox('ストリームが完了通知なしで途切れると error を返し会話履歴を保存しない')]
    public function incompleteStreamBecomesErrorWithoutSavingHistory(): void
    {
        $store = new FakeConversationStore();
        $provider = $this->provider($store);
        $provider->stubStreamChunks = [
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"途中\"}]}}]}\n\n",
        ];

        $events = [];
        $provider->streamText(
            new GenerationRequest('gemini-2.5-flash', [Message::user(ContentPart::text('a'))]),
            static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_ERROR],
            array_map(static fn(StreamEvent $event): string => $event->type, $events)
        );
        self::assertIsString($events[1]->message);
        self::assertStringContainsString('途中', $events[1]->message);
    }

    #[Test]
    #[TestDox('listModels は generateContent 対応モデルだけを models/ 接頭辞なしで返す')]
    public function listModelsFiltersAndStripsPrefix(): void
    {
        $provider = $this->provider();
        $provider->stubGetResult = json_encode([
            'models' => [
                ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
                ['name' => 'models/embedding-001', 'supportedGenerationMethods' => ['embedContent']],
                ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-2.5-flash-preview-tts', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-2.0-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3.1-flash-lite-preview', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3.1-pro-preview'],
            ],
        ]);

        self::assertSame(
            ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-3.1-flash-lite-preview'],
            $provider->listModels()
        );
    }

    #[Test]
    #[TestDox('listModels は API キー未設定なら通信せず null、エラー応答でも null を返す')]
    public function listModelsReturnsNullWithoutKeyOrOnError(): void
    {
        $unconfigured = new StubGeminiProvider(new Credentials(''));
        self::assertNull($unconfigured->listModels());
        self::assertNull($unconfigured->lastUrl);

        $provider = $this->provider();
        $provider->stubGetResult = '{"error":{"code":401,"message":"bad key","status":"UNAUTHENTICATED"}}';
        self::assertNull($provider->listModels());
    }
}
