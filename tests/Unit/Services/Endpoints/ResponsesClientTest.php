<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Endpoints;

use Acms\Plugins\AI\Tests\Support\StubResponsesClient;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ResponsesClient のコンテンツ生成・構造化出力フォーマット指定・リクエスト実行結果の扱いを固定する。
 *
 * 実通信は {@see StubResponsesClient} で差し替え、request() が「応答を decode して返す／通信失敗・
 * 例外時に null を返す」こと、text.format を設定したときだけペイロードへ含めることを検証する。
 */
final class ResponsesClientTest extends TestCase
{
    private function client(): StubResponsesClient
    {
        $client = new StubResponsesClient('sk-test-key', 'gpt-5.4-mini');
        $client->createPayload();
        return $client;
    }

    #[Test]
    #[TestDox('createImageContent は input_image コンテンツを組み立てる')]
    public function createImageContentBuildsInputImage(): void
    {
        $client = $this->client();

        self::assertSame(
            ['type' => 'input_image', 'image_url' => 'https://example.com/a.png'],
            $client->createImageContent('https://example.com/a.png')
        );
    }

    #[Test]
    #[TestDox('createOutputTextContent は output_text コンテンツを組み立てる')]
    public function createOutputTextContentBuildsOutputText(): void
    {
        $client = $this->client();

        self::assertSame(
            ['type' => 'output_text', 'text' => 'done'],
            $client->createOutputTextContent('done')
        );
    }

    #[Test]
    #[TestDox('setTextFormat を設定すると text.format としてペイロードへ入る')]
    public function textFormatIsIncludedWhenSet(): void
    {
        $client = $this->client();
        $client->stubResult = '{}';
        $format = ['type' => 'json_schema', 'name' => 'tag_suggestions', 'strict' => true];
        $client->setTextFormat($format);
        $client->request();

        $payload = $client->capturedPayload();
        self::assertSame($format, $payload['text']['format']);
    }

    #[Test]
    #[TestDox('setTextFormat を設定しなければ text はペイロードに含めない')]
    public function textFormatOmittedWhenUnset(): void
    {
        $client = $this->client();
        $client->stubResult = '{}';
        $client->request();

        self::assertArrayNotHasKey('text', $client->capturedPayload());
    }

    #[Test]
    #[TestDox('createPayload は前回の text.format もリセットする')]
    public function createPayloadResetsTextFormat(): void
    {
        $client = $this->client();
        $client->stubResult = '{}';
        $client->setTextFormat(['type' => 'json_schema', 'name' => 'stale']);
        // createPayload をやり直すと text.format も消える。
        $client->createPayload();
        $client->request();

        self::assertArrayNotHasKey('text', $client->capturedPayload());
    }

    #[Test]
    #[TestDox('request は応答ボディを decode したオブジェクトを返す')]
    public function requestReturnsDecodedResponse(): void
    {
        $client = $this->client();
        $client->stubResult = '{"id":"resp_1","status":"completed"}';

        $result = $client->request();

        self::assertIsObject($result);
        self::assertSame('resp_1', $result->id);
        self::assertSame('completed', $result->status);
    }

    #[Test]
    #[TestDox('通信が空応答（false）を返したときは null を返す')]
    public function requestReturnsNullOnEmptyResponse(): void
    {
        $client = $this->client();
        $client->stubResult = false;

        self::assertNull($client->request());
    }

    #[Test]
    #[TestDox('通信で例外が発生したときは握りつぶして null を返す')]
    public function requestReturnsNullWhenExecThrows(): void
    {
        $client = $this->client();
        $client->throwOnExec = true;

        self::assertNull($client->request());
    }
}
