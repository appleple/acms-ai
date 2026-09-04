<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Conversation;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Tests\Support\FakeConversationStore;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 会話継続トークンの発行と履歴の直列化・復元・保持上限を固定する。
 * キャッシュ I/O は {@see FakeConversationStore} のシーム差し替えで代替する。
 */
final class ConversationStoreTest extends TestCase
{
    #[Test]
    #[TestDox('save は 32 桁 hex のトークンを発行し、load で履歴を復元できる')]
    public function saveAndLoadRoundTrip(): void
    {
        $store = new FakeConversationStore();

        $token = $store->save(null, [
            Message::user(ContentPart::text('質問です')),
            Message::assistant(ContentPart::text('答えです')),
        ]);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $token);

        $history = $store->load($token);
        self::assertCount(2, $history);
        self::assertSame(Message::ROLE_USER, $history[0]->role);
        self::assertSame('質問です', $history[0]->parts[0]->value);
        self::assertSame(Message::ROLE_ASSISTANT, $history[1]->role);
        self::assertSame('答えです', $history[1]->parts[0]->value);
    }

    #[Test]
    #[TestDox('既存トークンを渡すと同じトークンのまま上書き保存する')]
    public function savingWithExistingTokenKeepsToken(): void
    {
        $store = new FakeConversationStore();
        $token = $store->save(null, [Message::user(ContentPart::text('1'))]);

        $again = $store->save($token, [
            Message::user(ContentPart::text('1')),
            Message::assistant(ContentPart::text('2')),
        ]);

        self::assertSame($token, $again);
        self::assertCount(2, $store->load($token));
    }

    #[Test]
    #[TestDox('不正な形式のトークンは使わず新規発行する（キャッシュキー汚染の防御）')]
    public function invalidTokenIsReplaced(): void
    {
        $store = new FakeConversationStore();

        $token = $store->save('../evil-key', [Message::user(ContentPart::text('a'))]);

        self::assertNotSame('../evil-key', $token);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $token);
        self::assertSame([], $store->load('../evil-key'));
    }

    #[Test]
    #[TestDox('未知トークン・破損データの load は空履歴を返す')]
    public function loadToleratesMissingOrCorruptData(): void
    {
        $store = new FakeConversationStore();

        self::assertSame([], $store->load(str_repeat('0', 32)));

        $token = str_repeat('a', 32);
        $store->storage['ai_conversation_' . $token] = '{broken json';
        self::assertSame([], $store->load($token));

        $store->storage['ai_conversation_' . $token] = '[{"role":"user"},"junk",{"role":"user","text":"生きてる"}]';
        $history = $store->load($token);
        self::assertCount(1, $history);
        self::assertSame('生きてる', $history[0]->parts[0]->value);
    }

    #[Test]
    #[TestDox('履歴は上限を超えると古いものから切り捨てる')]
    public function historyIsCappedFromTheOldest(): void
    {
        $store = new FakeConversationStore();
        $messages = [];
        for ($i = 1; $i <= 30; $i++) {
            $messages[] = Message::user(ContentPart::text("msg{$i}"));
        }

        $token = $store->save(null, $messages);
        $history = $store->load($token);

        self::assertCount(24, $history);
        self::assertSame('msg7', $history[0]->parts[0]->value);
        self::assertSame('msg30', $history[23]->parts[0]->value);
    }

    #[Test]
    #[TestDox('画像パートは保存せず、テキストパートだけを保持する')]
    public function imagePartsAreNotPersisted(): void
    {
        $store = new FakeConversationStore();

        $token = $store->save(null, [
            Message::user(ContentPart::text('画像を見て'), ContentPart::image('https://example.com/a.jpg')),
            Message::user(ContentPart::image('https://example.com/only-image.jpg')),
        ]);
        $history = $store->load($token);

        // テキストを持つメッセージだけが残り、そのテキストのみ保持される。
        self::assertCount(1, $history);
        self::assertCount(1, $history[0]->parts);
        self::assertSame('画像を見て', $history[0]->parts[0]->value);
    }

    #[Test]
    #[TestDox('保存には有効期限を渡す')]
    public function savesWithLifetime(): void
    {
        $store = new FakeConversationStore();
        $store->save(null, [Message::user(ContentPart::text('a'))]);

        self::assertNotSame([], $store->lifetimes);
        self::assertGreaterThan(0, $store->lifetimes[0]);
    }
}
