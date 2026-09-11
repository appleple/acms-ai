<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Vision;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Vision\MediaImageLoader;
use Acms\Plugins\AI\Tests\Support\MediaImageLoaderMediaStub;
use Acms\Plugins\AI\Tests\Support\MediaImageLoaderStorageStub;
use Acms\Services\Facades\Media;
use Acms\Services\Facades\PublicStorage;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(MediaImageLoader::class)]
final class MediaImageLoaderTest extends TestCase
{
    private object $originalMedia;
    private object $originalPublicStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMedia = Media::getInstance();
        $this->originalPublicStorage = PublicStorage::getInstance();
    }

    protected function tearDown(): void
    {
        Media::swap($this->originalMedia);
        PublicStorage::swap($this->originalPublicStorage);
        parent::tearDown();
    }

    #[Test]
    #[TestDox('現在ブログの範囲外にあるメディアは対象ブログの権限検査前に拒否する')]
    public function rejectsMediaOutsideCurrentBlogScope(): void
    {
        $media = new MediaImageLoaderMediaStub($this->media(200));
        Media::swap($media);
        $scopeCalls = [];
        $loader = new MediaImageLoader(
            static function (int $mediaBlogId, int $requestBlogId) use (&$scopeCalls): bool {
                $scopeCalls[] = [$mediaBlogId, $requestBlogId];
                return false;
            }
        );

        try {
            $loader->load(99);
            self::fail('範囲外のメディアが拒否されませんでした。');
        } catch (\RuntimeException $e) {
            self::assertSame('このメディアを編集する権限がありません。', $e->getMessage());
        }

        self::assertSame([[200, BID]], $scopeCalls);
        self::assertSame([99], $media->getMediaIds);
        self::assertSame([], $media->validatedBlogIds);
        self::assertSame([], $media->editedMediaIds);
    }

    #[Test]
    #[TestDox('メディアの所属ブログに対する利用権限がなければ拒否する')]
    public function validatesPermissionAgainstMediaBlog(): void
    {
        $media = new MediaImageLoaderMediaStub($this->media(200), false);
        Media::swap($media);
        $loader = new MediaImageLoader(static fn (): bool => true);

        try {
            $loader->load(99);
            self::fail('対象ブログの権限がないメディアが拒否されませんでした。');
        } catch (\RuntimeException $e) {
            self::assertSame('このメディアを編集する権限がありません。', $e->getMessage());
        }

        self::assertSame([200], $media->validatedBlogIds);
        self::assertSame([], $media->editedMediaIds);
    }

    #[Test]
    #[TestDox('現在ブログの範囲内かつ編集可能な画像だけをストレージから読み込む')]
    public function loadsAuthorizedMediaWithinCurrentBlogScope(): void
    {
        $media = new MediaImageLoaderMediaStub($this->media(200));
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2ZQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($bytes);
        $storage = new MediaImageLoaderStorageStub($bytes);
        Media::swap($media);
        PublicStorage::swap($storage);
        $loader = new MediaImageLoader(static fn (): bool => true);

        $part = $loader->load(99);

        self::assertSame(ContentPart::TYPE_IMAGE_DATA, $part->type);
        self::assertSame('image/png', $part->mimeType);
        self::assertSame(base64_encode($bytes), $part->value);
        self::assertSame([200], $media->validatedBlogIds);
        self::assertSame([99], $media->editedMediaIds);
        self::assertSame(1, $storage->getCalls);
    }

    /** @return array<string, mixed> */
    private function media(int $blogId): array
    {
        return [
            'mid' => 99,
            'bid' => $blogId,
            'status' => '',
            'path' => 'security-test.png',
            'type' => 'image',
        ];
    }
}
