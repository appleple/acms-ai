<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\ResponsesClient;

/**
 * OpenAiProvider::generateText の検証用ダブル。
 *
 * 内部の {@see ResponsesClient} を実通信しない {@see StubResponsesClient} へ差し替え、
 * リクエスト変換（メッセージ → input、outputSchema → text.format、continuationToken →
 * previous_response_id）を記録済みペイロードから検証できるようにする。
 */
final class StubOpenAiProvider extends OpenAiProvider
{
    /** @var string|false generateText 内で生成する StubResponsesClient が返す応答ボディ */
    public string|false $stubResult = '{}';

    /** @var StubResponsesClient|null 直近に生成したスタブクライアント（ペイロード検証用） */
    public ?StubResponsesClient $lastClient = null;

    protected function responsesClient(string $model): ResponsesClient
    {
        $client = new StubResponsesClient('sk-stub', $model);
        $client->stubResult = $this->stubResult;
        $this->lastClient = $client;

        return $client;
    }
}
