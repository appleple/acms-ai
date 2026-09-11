<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Integration\Services;

use Acms\Plugins\AI\Services\AI;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\TagSeeder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * コア tag テーブルから既存タグ名の一覧を取り出す AI::getTagNameAll の DB 挙動を検証する。
 *
 * タグ生成時に「既存タグ表記への寄せ」を促すための材料になる。各テストはトランザクション内で実行され、
 * 終了時に自動ロールバックされるため実データを汚さない。
 */
final class GetTagNameAllTest extends DatabaseTestCase
{
    private const TEST_BLOG_ID = 2147483646;
    private const TEST_ENTRY_ID = 2147483600;

    #[Test]
    #[TestDox('登録済みのタグ名を重複なく返す')]
    public function returnsDistinctTagNames(): void
    {
        // 同名タグ（PHP）を別エントリーにも紐付け、重複が畳まれることを確認する。
        TagSeeder::seed(self::TEST_ENTRY_ID, self::TEST_BLOG_ID, 'PHP', 1);
        TagSeeder::seed(self::TEST_ENTRY_ID, self::TEST_BLOG_ID, 'a-blog cms', 2);
        TagSeeder::seed(self::TEST_ENTRY_ID + 1, self::TEST_BLOG_ID, 'PHP', 1);

        $tags = AI::getTagNameAll(self::TEST_BLOG_ID);

        sort($tags);
        self::assertSame(['PHP', 'a-blog cms'], $tags);
    }

    #[Test]
    #[TestDox('指定したブログ以外のタグを返さない')]
    public function excludesTagsFromOtherBlogs(): void
    {
        TagSeeder::seed(self::TEST_ENTRY_ID, self::TEST_BLOG_ID, '対象ブログのタグ', 1);
        TagSeeder::seed(self::TEST_ENTRY_ID + 1, self::TEST_BLOG_ID - 1, '別ブログのタグ', 1);

        self::assertSame(['対象ブログのタグ'], AI::getTagNameAll(self::TEST_BLOG_ID));
    }

    #[Test]
    #[TestDox('タグが 1 件も無ければ空配列を返す')]
    public function returnsEmptyArrayWhenNoTags(): void
    {
        self::assertSame([], AI::getTagNameAll(self::TEST_BLOG_ID));
    }
}
