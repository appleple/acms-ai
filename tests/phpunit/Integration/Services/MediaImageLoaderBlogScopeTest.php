<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Integration\Services;

use Acms\Plugins\AI\Services\AI\Vision\MediaImageLoader;
use Acms\Plugins\AI\Tests\Support\MediaImageLoaderMediaStub;
use Acms\Services\Facades\Database;
use Acms\Services\Facades\Media;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

#[CoversClass(MediaImageLoader::class)]
final class MediaImageLoaderBlogScopeTest extends DatabaseTestCase
{
    private const CHILD_BLOG_ID = 2147483601;
    private const UNRELATED_BLOG_ID = 2147483602;

    private object $originalMedia;

    protected function setUpDatabase(): void
    {
        $update = SQL::newUpdate('blog');
        $update->addUpdate('blog_right', 4);
        $update->addWhereOpr('blog_id', BID);
        Database::query($update->get(dsn()), 'exec');

        BlogSeeder::seed([
            'blog_id' => self::CHILD_BLOG_ID,
            'blog_name' => 'AI media child blog',
            'blog_code' => 'ai-media-child',
            'blog_parent' => BID,
            'blog_left' => 2,
            'blog_right' => 3,
        ]);
        BlogSeeder::seed([
            'blog_id' => self::UNRELATED_BLOG_ID,
            'blog_name' => 'AI media unrelated blog',
            'blog_code' => 'ai-media-unrelated',
            'blog_parent' => 0,
            'blog_left' => 5,
            'blog_right' => 6,
        ]);
        $this->clearBlogCaches();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMedia = Media::getInstance();
    }

    protected function tearDown(): void
    {
        Media::swap($this->originalMedia);
        $this->clearBlogCaches();
        parent::tearDown();
    }

    #[Test]
    #[TestDox('現在ブログの子ブログに属するメディアはブログスコープを通過する')]
    public function acceptsMediaInDescendantBlogScope(): void
    {
        $media = new MediaImageLoaderMediaStub($this->media(self::CHILD_BLOG_ID), false);
        Media::swap($media);

        $this->assertPermissionDenied(new MediaImageLoader());

        self::assertSame([self::CHILD_BLOG_ID], $media->validatedBlogIds);
    }

    #[Test]
    #[TestDox('無関係なブログに属するメディアは対象ブログの権限検査前に拒否する')]
    public function rejectsMediaInUnrelatedBlogScope(): void
    {
        $media = new MediaImageLoaderMediaStub($this->media(self::UNRELATED_BLOG_ID), false);
        Media::swap($media);

        $this->assertPermissionDenied(new MediaImageLoader());

        self::assertSame([], $media->validatedBlogIds);
        self::assertSame([], $media->editedMediaIds);
    }

    private function assertPermissionDenied(MediaImageLoader $loader): void
    {
        try {
            $loader->load(99);
            self::fail('権限のないメディアが拒否されませんでした。');
        } catch (\RuntimeException $e) {
            self::assertSame('このメディアを編集する権限がありません。', $e->getMessage());
        }
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

    private function clearBlogCaches(): void
    {
        \ACMS_RAM::_mapping('blog', BID, null);
        \ACMS_RAM::_mapping('blog', self::CHILD_BLOG_ID, null);
        \ACMS_RAM::_mapping('blog', self::UNRELATED_BLOG_ID, null);
        \ACMS_RAM::cacheDelete();
    }
}
