<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Endpoints;

use Acms\Plugins\AI\Tests\Support\StubResponsesClient;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Responses API へのリクエスト組み立て（EndpointTrait）の純粋ロジックを固定する。
 *
 * input の蓄積・ロール別のコンテンツ種別・instructions / previous_response_id の反映・状態リセット・
 * 認証ヘッダの組み立てを、実通信を差し替えた {@see StubResponsesClient} が exec() へ渡すペイロードで検証する。
 */
final class EndpointTraitTest extends TestCase
{
    private function client(): StubResponsesClient
    {
        $client = new StubResponsesClient('sk-test-key', 'gpt-5.4');
        $client->stubResult = '{}';
        return $client;
    }

    #[Test]
    #[TestDox('createPayload 後の request はモデル・store・空 input を含む')]
    public function payloadCarriesModelAndStore(): void
    {
        $client = $this->client();
        $client->createPayload();
        $client->request();

        $payload = $client->capturedPayload();
        self::assertSame('gpt-5.4', $payload['model']);
        self::assertTrue($payload['store']);
        self::assertSame([], $payload['input']);
    }

    #[Test]
    #[TestDox('createTextContent はユーザーを input_text、アシスタントを output_text にする')]
    public function textContentTypeDependsOnRole(): void
    {
        $client = $this->client();

        self::assertSame(
            ['type' => 'input_text', 'text' => 'hello'],
            $client->createTextContent('hello')
        );
        self::assertSame(
            ['type' => 'input_text', 'text' => 'hello'],
            $client->createTextContent('hello', 'user')
        );
        self::assertSame(
            ['type' => 'output_text', 'text' => 'ok'],
            $client->createTextContent('ok', 'assistant')
        );
    }

    #[Test]
    #[TestDox('addInput は role とコンテンツを input に順番どおり積む')]
    public function addInputAccumulatesMessages(): void
    {
        $client = $this->client();
        $client->createPayload();
        $client->addInput('user', [$client->createTextContent('first')]);
        $client->addInput('assistant', [$client->createTextContent('second', 'assistant')]);
        $client->request();

        $input = $client->capturedPayload()['input'];
        self::assertCount(2, $input);
        self::assertSame('user', $input[0]['role']);
        self::assertSame('input_text', $input[0]['content'][0]['type']);
        self::assertSame('first', $input[0]['content'][0]['text']);
        self::assertSame('assistant', $input[1]['role']);
        self::assertSame('output_text', $input[1]['content'][0]['type']);
    }

    #[Test]
    #[TestDox('setInstructions / setPreviousResponseId はペイロードに反映される')]
    public function instructionsAndPreviousResponseIdAreReflected(): void
    {
        $client = $this->client();
        $client->createPayload();
        $client->setInstructions('be concise');
        $client->setPreviousResponseId('resp_123');
        $client->request();

        $payload = $client->capturedPayload();
        self::assertSame('be concise', $payload['instructions']);
        self::assertSame('resp_123', $payload['previous_response_id']);
    }

    #[Test]
    #[TestDox('未設定なら instructions / previous_response_id はペイロードに含めない')]
    public function optionalFieldsOmittedWhenUnset(): void
    {
        $client = $this->client();
        $client->createPayload();
        $client->request();

        $payload = $client->capturedPayload();
        self::assertArrayNotHasKey('instructions', $payload);
        self::assertArrayNotHasKey('previous_response_id', $payload);
    }

    #[Test]
    #[TestDox('createPayload は以前の input / instructions / previousResponseId をリセットする')]
    public function createPayloadResetsState(): void
    {
        $client = $this->client();
        $client->createPayload();
        $client->addInput('user', [$client->createTextContent('stale')]);
        $client->setInstructions('stale');
        $client->setPreviousResponseId('stale');

        // 再度 createPayload するとすべて初期化される。
        $client->createPayload();
        $client->request();

        $payload = $client->capturedPayload();
        self::assertSame([], $payload['input']);
        self::assertArrayNotHasKey('instructions', $payload);
        self::assertArrayNotHasKey('previous_response_id', $payload);
    }

    #[Test]
    #[TestDox('buildHeaders は JSON と Bearer 認証ヘッダを渡す')]
    public function buildHeadersCarryAuthorization(): void
    {
        $client = $this->client();
        $client->createPayload();
        $client->request();

        self::assertNotNull($client->capturedHeaders);
        self::assertContains('Content-Type: application/json', $client->capturedHeaders);
        self::assertContains('Authorization: Bearer sk-test-key', $client->capturedHeaders);
    }
}
