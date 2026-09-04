<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Vision;

use Acms\Plugins\AI\Services\AI\Vision\DataUrl;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * data URL の生成・解析（build と parse の往復・非対応形式の拒否）を固定する。
 */
final class DataUrlTest extends TestCase
{
    #[Test]
    #[TestDox('build と parse で往復できる')]
    public function buildAndParseRoundTrip(): void
    {
        $url = DataUrl::build('image/jpeg', 'aGVsbG8=');

        self::assertSame('data:image/jpeg;base64,aGVsbG8=', $url);
        self::assertSame(['mimeType' => 'image/jpeg', 'data' => 'aGVsbG8='], DataUrl::parse($url));
    }

    #[Test]
    #[TestDox('data URL 以外・base64 以外・データ空は null を返す')]
    public function parseRejectsNonDataUrls(): void
    {
        self::assertNull(DataUrl::parse('https://example.com/image.jpg'));
        self::assertNull(DataUrl::parse('data:text/plain,hello'));
        self::assertNull(DataUrl::parse('data:image/png;base64,'));
        self::assertNull(DataUrl::parse(''));
    }

    #[Test]
    #[TestDox('svg+xml のような複合 MIME タイプも解析できる')]
    public function parsesCompositeMimeTypes(): void
    {
        $parsed = DataUrl::parse('data:image/svg+xml;base64,PHN2Zz4=');

        self::assertNotNull($parsed);
        self::assertSame('image/svg+xml', $parsed['mimeType']);
    }
}
