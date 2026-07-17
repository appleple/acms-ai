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
    #[Test]
    #[TestDox('登録済みのタグ名を重複なく返す')]
    public function returnsDistinctTagNames(): void
    {
        // 同名タグ（PHP）を別エントリーにも紐付け、重複が畳まれることを確認する。
        TagSeeder::seed(1, 1, 'PHP', 1);
        TagSeeder::seed(1, 1, 'a-blog cms', 2);
        TagSeeder::seed(2, 1, 'PHP', 1);

        $tags = AI::getTagNameAll();

        sort($tags);
        self::assertSame(['PHP', 'a-blog cms'], $tags);
    }

    #[Test]
    #[TestDox('タグが 1 件も無ければ空配列を返す')]
    public function returnsEmptyArrayWhenNoTags(): void
    {
        self::assertSame([], AI::getTagNameAll());
    }
}
