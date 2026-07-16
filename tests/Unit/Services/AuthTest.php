<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Tests\Support\FakeAuthService;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAI /models への認証（AI::auth）の「引数バリデーション・レスポンス解析・エラー分岐・
 * 利用可能モデル絞り込み」を固定する。
 *
 * 実通信は {@see FakeAuthService} で差し替え、決定的に検証する。cURL 自体（AI::httpGetJson）は
 * I/O 境界のため実機/E2E で担保する（ユニット対象外）。
 */
final class AuthTest extends TestCase
{
    #[Test]
    #[TestDox('組織 ID・プロジェクト ID・API キーのいずれかが空なら通信せず null を返す')]
    public function returnsNullWhenAnyCredentialEmpty(): void
    {
        $service = new FakeAuthService();
        $service->body = '{"data":[{"id":"gpt-5.4"}]}';

        $this->assertNull($service->auth('', 'proj', 'key'));
        $this->assertNull($service->auth('org', '', 'key'));
        $this->assertNull($service->auth('org', 'proj', ''));
        // 通信は一度も行われない。
        $this->assertNull($service->requestedUrl);
    }

    #[Test]
    #[TestDox('認証情報が揃えば OpenAI の /models エンドポイントへ問い合わせる')]
    public function callsModelsEndpointWhenConfigured(): void
    {
        $service = new FakeAuthService();
        $service->body = '{"data":[]}';

        $service->auth('org', 'proj', 'key');

        $this->assertSame('https://api.openai.com/v1/models', $service->requestedUrl);
    }

    #[Test]
    #[TestDox('通信失敗（cURL エラー）は握りつぶして null を返す')]
    public function returnsNullOnTransportFailure(): void
    {
        $service = new FakeAuthService();
        $service->fail = true;

        $this->assertNull($service->auth('org', 'proj', 'key'));
    }

    #[Test]
    #[TestDox('不正な JSON 応答は null を返す')]
    public function returnsNullOnInvalidJson(): void
    {
        $service = new FakeAuthService();
        $service->body = 'not-json';

        $this->assertNull($service->auth('org', 'proj', 'key'));
    }

    #[Test]
    #[TestDox('オブジェクト以外の JSON（配列・スカラ）は null を返す')]
    public function returnsNullOnNonObjectJson(): void
    {
        $service = new FakeAuthService();

        $service->body = '[]';
        $this->assertNull($service->auth('org', 'proj', 'key'));

        $service->body = '"just a string"';
        $this->assertNull($service->auth('org', 'proj', 'key'));
    }

    #[Test]
    #[TestDox('error フィールドを含む応答は null を返す')]
    public function returnsNullWhenResponseHasError(): void
    {
        $service = new FakeAuthService();
        $service->body = '{"error":{"message":"Incorrect API key provided"}}';

        $this->assertNull($service->auth('org', 'proj', 'key'));
    }

    #[Test]
    #[TestDox('成功時は許可リストのモデルだけを配列で返す')]
    public function returnsOnlyAllowedModelsOnSuccess(): void
    {
        $service = new FakeAuthService();
        $service->body = json_encode([
            'data' => [
                ['id' => 'gpt-5.4'],
                ['id' => 'gpt-3.5-turbo'],
                ['id' => 'gpt-5.4-mini'],
                ['id' => 'dall-e-3'],
            ],
        ]) ?: '{}';

        $models = $service->auth('org', 'proj', 'key');

        $this->assertSame(['gpt-5.4', 'gpt-5.4-mini'], $models);
    }

    #[Test]
    #[TestDox('data の要素に id が無い／オブジェクトでないものは無視する')]
    public function skipsMalformedDataEntries(): void
    {
        $service = new FakeAuthService();
        $service->body = json_encode([
            'data' => [
                ['name' => 'no-id-here'],
                'not-an-object',
                ['id' => 'gpt-5.4-nano'],
            ],
        ]) ?: '{}';

        $models = $service->auth('org', 'proj', 'key');

        $this->assertSame(['gpt-5.4-nano'], $models);
    }

    #[Test]
    #[TestDox('data が無い応答は空配列を返す')]
    public function returnsEmptyArrayWhenNoData(): void
    {
        $service = new FakeAuthService();
        $service->body = '{"object":"list"}';

        $this->assertSame([], $service->auth('org', 'proj', 'key'));
    }
}
