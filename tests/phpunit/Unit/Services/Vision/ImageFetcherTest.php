<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Vision;

use Acms\Plugins\AI\Tests\Support\StubImageFetcher;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 画像取得後の検証ロジック（リダイレクト拒否・サイズ上限・MIME 判定・data URL 変換）を固定する。
 * 実通信は {@see StubImageFetcher} で差し替える。
 */
final class ImageFetcherTest extends TestCase
{
    /** 1x1 の PNG（最小の正当な画像バイト列） */
    private function pngBytes(): string
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $bytes = base64_decode($base64, true);
        self::assertIsString($bytes);

        return $bytes;
    }

    #[Test]
    #[TestDox('画像を取得すると MIME 判定つきの data URL を返す')]
    public function returnsDataUrlWithSniffedMime(): void
    {
        $fetcher = new StubImageFetcher();
        $fetcher->stubBody = $this->pngBytes();

        $dataUrl = $fetcher->fetchAsDataUrl('https://example.com/pixel.png');

        self::assertStringStartsWith('data:image/png;base64,', $dataUrl);
        self::assertSame($this->pngBytes(), base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true));
    }

    #[Test]
    #[TestDox('リダイレクト応答は追わずに拒否する')]
    public function rejectsRedirects(): void
    {
        $fetcher = new StubImageFetcher();
        $fetcher->stubStatus = 302;
        $fetcher->stubBody = $this->pngBytes();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('リダイレクト');

        $fetcher->fetchAsDataUrl('https://example.com/redirect.png');
    }

    #[Test]
    #[TestDox('HTTP エラー・空ボディは取得失敗として例外にする')]
    public function rejectsErrorsAndEmptyBody(): void
    {
        $fetcher = new StubImageFetcher();
        $fetcher->stubStatus = 404;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('取得に失敗');

        $fetcher->fetchAsDataUrl('https://example.com/missing.png');
    }

    #[Test]
    #[TestDox('画像でないコンテンツ（HTML 等）は非対応形式として拒否する')]
    public function rejectsNonImageContent(): void
    {
        $fetcher = new StubImageFetcher();
        $fetcher->stubBody = '<!DOCTYPE html><html><body>not an image</body></html>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('対応していない画像形式');

        $fetcher->fetchAsDataUrl('https://example.com/page.html');
    }

    #[Test]
    #[TestDox('サイズ上限（8MB）を超える画像は拒否する')]
    public function rejectsOversizedImages(): void
    {
        $fetcher = new StubImageFetcher();
        $fetcher->stubBody = str_repeat('a', 8 * 1024 * 1024 + 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('大きすぎます');

        $fetcher->fetchAsDataUrl('https://example.com/huge.png');
    }
}
