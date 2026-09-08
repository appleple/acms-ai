<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Conversation\ConversationStore;

/**
 * ConversationStore の検証用ダブル。
 *
 * 本体 temp キャッシュへの I/O 境界（cacheGet / cachePut）を配列で差し替え、
 * トークン発行・履歴の直列化/復元・保持上限のロジックを実キャッシュなしで検証できるようにする。
 */
final class FakeConversationStore extends ConversationStore
{
    /** @var array<string, string> キー → 保存値 */
    public array $storage = [];

    /** @var list<int> put に渡された lifetime の記録 */
    public array $lifetimes = [];

    protected function cacheGet(string $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    protected function cachePut(string $key, string $value, int $lifetime): void
    {
        $this->storage[$key] = $value;
        $this->lifetimes[] = $lifetime;
    }
}
