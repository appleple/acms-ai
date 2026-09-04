<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Vision\ImageFetcher;

/**
 * ImageFetcher の検証用ダブル。
 * curl 依存の I/O 境界（httpGetBinary）を差し替え、取得後の検証ロジック
 * （リダイレクト拒否・サイズ上限・MIME 判定）を実通信なしで検証できるようにする。
 */
final class StubImageFetcher extends ImageFetcher
{
    /** @var int httpGetBinary が返す HTTP ステータス */
    public int $stubStatus = 200;

    /** @var string httpGetBinary が返すボディ */
    public string $stubBody = '';

    /** @var string|null 直近に取得した URL */
    public ?string $lastUrl = null;

    protected function httpGetBinary(string $url): array
    {
        $this->lastUrl = $url;

        return [$this->stubStatus, $this->stubBody];
    }
}
