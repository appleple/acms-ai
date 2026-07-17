<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;

/**
 * OpenAiProvider のテスト用ダブル。
 *
 * OpenAI との実通信（{@see OpenAiProvider::httpGetJson()}）だけを差し替え、authenticate() の
 * 「JSON 解析・エラー分岐・モデル絞り込み」ロジックを決定的に検証できるようにする。$body に返す
 * レスポンスボディを、$fail に cURL 失敗（例外）を模すフラグを与える。
 */
final class FakeOpenAiProvider extends OpenAiProvider
{
    /** @var string httpGetJson が返すレスポンスボディ */
    public string $body = '{}';

    /** @var bool true なら通信失敗（cURL エラー）を模して例外を投げる */
    public bool $fail = false;

    /** @var string|null 最後に呼ばれた URL（呼び出し検証用） */
    public ?string $requestedUrl = null;

    /**
     * @param list<string> $headers
     */
    protected function httpGetJson(string $url, array $headers): string
    {
        $this->requestedUrl = $url;
        if ($this->fail) {
            throw new \Exception('cURL Error: simulated transport failure');
        }
        return $this->body;
    }
}
