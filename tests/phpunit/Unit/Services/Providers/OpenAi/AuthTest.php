<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Tests\Support\FakeOpenAiProvider;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAI /models へのモデル列挙（OpenAiProvider::listModels）の「認証情報バリデーション・レスポンス解析・
 * エラー分岐・利用可能モデル絞り込み」を固定する。
 *
 * 実通信は {@see FakeOpenAiProvider} で差し替え、決定的に検証する。cURL 自体（OpenAiProvider::httpGetJson）は
 * I/O 境界のため実機/E2E で担保する（ユニット対象外）。
 */
final class AuthTest extends TestCase
{
    /**
     * @param array<string, string> $attributes organizationId / projectId
     */
    private function provider(string $apiKey, array $attributes, string $body = '{}', bool $fail = false): FakeOpenAiProvider
    {
        $provider = new FakeOpenAiProvider(new Credentials($apiKey, $attributes));
        $provider->body = $body;
        $provider->fail = $fail;
        return $provider;
    }

    /**
     * 3 点認証が揃った状態の既定プロバイダ。
     */
    private function configured(string $body = '{}', bool $fail = false): FakeOpenAiProvider
    {
        return $this->provider('key', ['organizationId' => 'org', 'projectId' => 'proj'], $body, $fail);
    }

    #[Test]
    #[TestDox('API キーが空なら通信せず null を返す')]
    public function returnsNullWhenApiKeyEmpty(): void
    {
        $noKey = $this->provider('', ['organizationId' => 'org', 'projectId' => 'proj'], '{"data":[{"id":"gpt-5.4"}]}');

        self::assertNull($noKey->listModels());
        // 通信は一度も行われない。
        self::assertNull($noKey->requestedUrl);
    }

    #[Test]
    #[TestDox('Organization ID / Project ID は任意（空でも API キーがあれば問い合わせる）')]
    public function queriesWithoutOrganizationAndProject(): void
    {
        $provider = $this->provider('key', ['organizationId' => '', 'projectId' => ''], '{"data":[{"id":"gpt-5.4"}]}');

        self::assertSame(['gpt-5.4'], $provider->listModels());
        self::assertSame('https://api.openai.com/v1/models', $provider->requestedUrl);
    }

    #[Test]
    #[TestDox('Organization ID / Project ID が空のときは空のヘッダーを送らない')]
    public function omitsEmptyOrganizationAndProjectHeaders(): void
    {
        $provider = $this->provider('key', ['organizationId' => '', 'projectId' => ''], '{"data":[]}');
        $provider->listModels();

        self::assertNotNull($provider->requestedHeaders);
        foreach ($provider->requestedHeaders as $header) {
            self::assertStringStartsNotWith('OpenAI-Organization:', $header);
            self::assertStringStartsNotWith('OpenAI-Project:', $header);
        }
    }

    #[Test]
    #[TestDox('Organization ID / Project ID が設定されているときはヘッダーへ付与する')]
    public function sendsOrganizationAndProjectHeadersWhenSet(): void
    {
        $provider = $this->configured('{"data":[]}');
        $provider->listModels();

        self::assertNotNull($provider->requestedHeaders);
        self::assertContains('OpenAI-Organization: org', $provider->requestedHeaders);
        self::assertContains('OpenAI-Project: proj', $provider->requestedHeaders);
    }

    #[Test]
    #[TestDox('認証情報が揃えば OpenAI の /models エンドポイントへ問い合わせる')]
    public function callsModelsEndpointWhenConfigured(): void
    {
        $provider = $this->configured('{"data":[]}');

        $provider->listModels();

        self::assertSame('https://api.openai.com/v1/models', $provider->requestedUrl);
    }

    #[Test]
    #[TestDox('通信失敗（cURL エラー）は握りつぶして null を返す')]
    public function returnsNullOnTransportFailure(): void
    {
        self::assertNull($this->configured('{}', true)->listModels());
    }

    #[Test]
    #[TestDox('不正な JSON 応答は null を返す')]
    public function returnsNullOnInvalidJson(): void
    {
        self::assertNull($this->configured('not-json')->listModels());
    }

    #[Test]
    #[TestDox('オブジェクト以外の JSON（配列・スカラ）は null を返す')]
    public function returnsNullOnNonObjectJson(): void
    {
        self::assertNull($this->configured('[]')->listModels());
        self::assertNull($this->configured('"just a string"')->listModels());
    }

    #[Test]
    #[TestDox('error フィールドを含む応答は null を返す')]
    public function returnsNullWhenResponseHasError(): void
    {
        self::assertNull($this->configured('{"error":{"message":"Incorrect API key provided"}}')->listModels());
    }

    #[Test]
    #[TestDox('成功時は API の返す全モデルを配列で返す（絞り込みは config で消費側が行う）')]
    public function returnsAllModelsOnSuccess(): void
    {
        $body = json_encode([
            'data' => [
                ['id' => 'gpt-5.4'],
                ['id' => 'gpt-3.5-turbo'],
                ['id' => 'gpt-5.4-mini'],
                ['id' => 'dall-e-3'],
            ],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(
            ['gpt-5.4', 'gpt-3.5-turbo', 'gpt-5.4-mini', 'dall-e-3'],
            $this->configured($body)->listModels()
        );
    }

    #[Test]
    #[TestDox('data の要素に id が無い／オブジェクトでないものは無視する')]
    public function skipsMalformedDataEntries(): void
    {
        $body = json_encode([
            'data' => [
                ['name' => 'no-id-here'],
                'not-an-object',
                ['id' => 'gpt-5.4-nano'],
            ],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(['gpt-5.4-nano'], $this->configured($body)->listModels());
    }

    #[Test]
    #[TestDox('data が無い応答は空配列を返す')]
    public function returnsEmptyArrayWhenNoData(): void
    {
        self::assertSame([], $this->configured('{"object":"list"}')->listModels());
    }
}
