<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatProvider;

/**
 * OpenAiCompatProvider の検証用ダブル。
 *
 * curl 依存の I/O 境界（httpGetJson / httpPostJson / httpPostStream）を実通信しない実装へ差し替え、
 * リクエスト変換（メッセージ → messages、outputSchema → response_format＋プロンプト指示、
 * 継続トークン → 履歴復元）と json_object フォールバックを記録済みペイロードから検証できるようにする。
 */
final class StubOpenAiCompatProvider extends OpenAiCompatProvider
{
    /** @var string httpGetJson（モデル一覧）が返す応答ボディ */
    public string $stubGetResult = '{}';

    /** @var list<string> httpPostJson が呼び出し順に返す応答ボディ（フォールバック再試行の検証用） */
    public array $stubPostResults = ['{}'];

    /** @var list<string> httpPostStream が $onBytes へ流すバイト列（チャンク分割を模す） */
    public array $stubStreamChunks = [];

    /** @var list<string> 送信した POST ボディの記録（呼び出し順） */
    public array $postBodies = [];

    /** @var list<string>|null 直近に送信したヘッダー */
    public ?array $lastHeaders = null;

    /** @var string|null 直近にアクセスした URL */
    public ?string $lastUrl = null;

    protected function httpGetJson(string $url, array $headers): string
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        return $this->stubGetResult;
    }

    protected function httpPostJson(string $url, array $headers, string $body): string
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->postBodies[] = $body;

        $index = count($this->postBodies) - 1;
        if (isset($this->stubPostResults[$index])) {
            return $this->stubPostResults[$index];
        }
        $last = end($this->stubPostResults);

        return $last === false ? '{}' : $last;
    }

    protected function httpPostStream(string $url, array $headers, string $body, callable $onBytes): void
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->postBodies[] = $body;

        foreach ($this->stubStreamChunks as $chunk) {
            $onBytes($chunk);
        }
    }

    /**
     * n 回目（0 始まり）の POST ボディを配列へ復元して返す。
     *
     * @return array<string, mixed>
     */
    public function capturedPayload(int $index = 0): array
    {
        $decoded = json_decode($this->postBodies[$index] ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }
}
