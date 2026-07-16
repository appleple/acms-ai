<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Endpoints\ResponsesClient;

/**
 * ResponsesClient のテスト用ダブル。
 *
 * 実通信（{@see ResponsesClient::exec()}）だけを差し替え、request() が組み立てる POST ペイロードを
 * 記録しつつ、任意の応答ボディ（またはエラー）を返す。これにより「input/instructions/
 * previous_response_id/text.format をペイロードへ正しく反映しているか」「応答の decode 結果を返すか」
 * 「通信失敗・空応答で null を返すか」を決定的に検証できる。
 */
final class StubResponsesClient extends ResponsesClient
{
    /** @var string|null request() が exec() に渡した JSON ペイロード */
    public ?string $capturedJson = null;

    /** @var list<string>|null request() が exec() に渡したヘッダ */
    public ?array $capturedHeaders = null;

    /** @var string|false exec() が返す応答ボディ。false は通信失敗（空応答）を表す */
    public string|false $stubResult = false;

    /** @var bool true なら exec() で例外を投げ、request() の catch 経路を検証する */
    public bool $throwOnExec = false;

    /**
     * @param list<string> $headers
     */
    public function exec(string $json, array $headers): string|false
    {
        $this->capturedJson = $json;
        $this->capturedHeaders = $headers;
        if ($this->throwOnExec) {
            throw new \Exception('cURL Error: simulated transport failure');
        }
        return $this->stubResult;
    }

    /**
     * request() が exec() に渡した JSON ペイロードを連想配列でデコードして返すテスト補助。
     *
     * @return array<string, mixed>
     */
    public function capturedPayload(): array
    {
        if ($this->capturedJson === null) {
            return [];
        }
        $decoded = json_decode($this->capturedJson, true);
        return is_array($decoded) ? $decoded : [];
    }
}
