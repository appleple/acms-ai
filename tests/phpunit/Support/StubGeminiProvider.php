<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiProvider;

/**
 * GeminiProvider の検証用ダブル。
 *
 * curl 依存の I/O 境界（httpGetJson / httpPostJson / httpPostStream / fetchInlineImage）を
 * 実通信しない実装へ差し替え、リクエスト変換（メッセージ → contents/parts、outputSchema →
 * responseSchema、継続トークン → 履歴復元、画像 → inlineData）を記録済みペイロードから検証できるようにする。
 */
final class StubGeminiProvider extends GeminiProvider
{
    /** @var string httpGetJson（モデル一覧）が返す応答ボディ */
    public string $stubGetResult = '{}';

    /** @var string httpPostJson（生成）が返す応答ボディ */
    public string $stubPostResult = '{}';

    /** @var list<string> httpPostStream が $onBytes へ流すバイト列（チャンク分割を模す） */
    public array $stubStreamChunks = [];

    /** @var array{mimeType: string, data: string}|null fetchInlineImage が返す値 */
    public ?array $stubInlineImage = ['mimeType' => 'image/jpeg', 'data' => 'c3R1Yg=='];

    /** @var string|null 直近に送信した POST ボディ（ペイロード検証用） */
    public ?string $lastPostBody = null;

    /** @var list<string>|null 直近に送信したヘッダー */
    public ?array $lastHeaders = null;

    /** @var string|null 直近にアクセスした URL */
    public ?string $lastUrl = null;

    /** @var list<string> fetchInlineImage に渡された URL の記録 */
    public array $fetchedImageUrls = [];

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
        $this->lastPostBody = $body;

        return $this->stubPostResult;
    }

    protected function httpPostStream(string $url, array $headers, string $body, callable $onBytes): void
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPostBody = $body;

        foreach ($this->stubStreamChunks as $chunk) {
            $onBytes($chunk);
        }
    }

    protected function fetchInlineImage(string $url): ?array
    {
        $this->fetchedImageUrls[] = $url;

        return $this->stubInlineImage;
    }

    /**
     * 直近の POST ボディを配列へ復元して返す。
     *
     * @return array<string, mixed>
     */
    public function capturedPayload(): array
    {
        $decoded = json_decode($this->lastPostBody ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }
}
