<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\Plugins\AI\Tests\Support\StubOpenAiProvider;
use Acms\Plugins\AI\Tests\Support\StubResponsesClient;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAiProvider がプロバイダ非依存の {@see GenerationRequest} を OpenAI Responses API の
 * ペイロードへ正しく変換し、応答から {@see \Acms\Plugins\AI\Services\AI\Contracts\GenerationResult}
 * を組み立てることを固定する。実通信は {@see StubOpenAiProvider} で差し替える。
 */
final class OpenAiProviderTest extends TestCase
{
    private function creds(): Credentials
    {
        return new Credentials('sk-test', ['organizationId' => 'org', 'projectId' => 'proj']);
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedPayload(StubOpenAiProvider $provider): array
    {
        $client = $provider->lastClient;
        if (!$client instanceof StubResponsesClient) {
            self::fail('stub ResponsesClient was not created');
        }

        return $client->capturedPayload();
    }

    #[Test]
    #[TestDox('id は openai を返す')]
    public function idIsOpenAi(): void
    {
        self::assertSame('openai', (new OpenAiProvider($this->creds()))->id());
    }

    #[Test]
    #[TestDox('テキスト生成・構造化出力・画像入力・ストリーミング・モデル列挙をサポートする')]
    public function supportsExpectedCapabilities(): void
    {
        $provider = new OpenAiProvider($this->creds());
        $capabilities = [
            Capability::TextGeneration,
            Capability::StructuredOutput,
            Capability::VisionInput,
            Capability::Streaming,
            Capability::ModelListing,
        ];
        foreach ($capabilities as $capability) {
            self::assertTrue($provider->supports($capability));
        }
    }

    #[Test]
    #[TestDox('generateText はメッセージ・instructions・継続トークンを Responses API ペイロードへ変換する')]
    public function generateTextMapsRequestToPayload(): void
    {
        $provider = new StubOpenAiProvider($this->creds());
        $provider->stubResult = '{"id":"resp_9","output":[{"type":"message","content":[{"type":"output_text","text":"OK"}]}]}';

        $request = new GenerationRequest(
            'gpt-5.4-mini',
            [
                Message::user(ContentPart::text('hi')),
                Message::assistant(ContentPart::text('prev')),
            ],
            'be nice',
            null,
            null,
            'resp_prev'
        );

        $result = $provider->generateText($request);

        self::assertSame('OK', $result->text);
        self::assertSame('resp_9', $result->continuationToken);

        $payload = $this->capturedPayload($provider);
        self::assertSame('gpt-5.4-mini', $payload['model']);
        self::assertSame('be nice', $payload['instructions']);
        self::assertSame('resp_prev', $payload['previous_response_id']);
        self::assertSame('user', $payload['input'][0]['role']);
        self::assertSame('input_text', $payload['input'][0]['content'][0]['type']);
        self::assertSame('assistant', $payload['input'][1]['role']);
        self::assertSame('output_text', $payload['input'][1]['content'][0]['type']);
        self::assertArrayNotHasKey('text', $payload);
    }

    #[Test]
    #[TestDox('outputSchema を与えると text.format(json_schema) として包む')]
    public function generateTextWrapsOutputSchema(): void
    {
        $provider = new StubOpenAiProvider($this->creds());
        $provider->stubResult = '{"output":[{"type":"message","content":[{"type":"output_text","text":"{}"}]}]}';

        $schema = ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]];
        $request = new GenerationRequest('gpt-5.4', [Message::user(ContentPart::text('go'))], null, $schema, 'my_schema');

        $provider->generateText($request);

        $payload = $this->capturedPayload($provider);
        self::assertSame('json_schema', $payload['text']['format']['type']);
        self::assertSame('my_schema', $payload['text']['format']['name']);
        self::assertTrue($payload['text']['format']['strict']);
        self::assertSame($schema, $payload['text']['format']['schema']);
    }

    #[Test]
    #[TestDox('画像パートは input_image コンテンツへ変換される')]
    public function generateTextMapsImageParts(): void
    {
        $provider = new StubOpenAiProvider($this->creds());
        $provider->stubResult = '{"output":[{"type":"message","content":[{"type":"output_text","text":"desc"}]}]}';

        $request = new GenerationRequest('gpt-5.4', [
            Message::user(ContentPart::text('describe'), ContentPart::image('https://example.com/a.png')),
        ]);

        $provider->generateText($request);

        $content = $this->capturedPayload($provider)['input'][0]['content'];
        self::assertSame(['type' => 'input_text', 'text' => 'describe'], $content[0]);
        self::assertSame(['type' => 'input_image', 'image_url' => 'https://example.com/a.png'], $content[1]);
    }

    #[Test]
    #[TestDox('本文が取得できないと text は null になる')]
    public function generateTextReturnsNullTextWhenNoOutput(): void
    {
        $provider = new StubOpenAiProvider($this->creds());
        $provider->stubResult = '{"status":"completed"}';

        $result = $provider->generateText(new GenerationRequest('gpt-5.4', [Message::user(ContentPart::text('x'))]));

        self::assertNull($result->text);
    }
}
