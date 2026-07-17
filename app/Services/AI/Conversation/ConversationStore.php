<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Conversation;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Services\Facades\Cache;

/**
 * サーバー側に会話状態を持たないプロバイダ（Anthropic など）向けの会話ストア。
 *
 * OpenAI は previous_response_id によりサーバー側で会話を継続できるが、毎回フル履歴を送る方式の
 * プロバイダでは、継続トークン（不透明なランダム値）をキーに履歴を本体の temp キャッシュへ保持して
 * プロバイダ内部で復元する（docs/adding-a-provider.md「会話継続トークンの扱い」参照）。
 * 消費側・フロントは従来どおり不透明トークンだけを受け渡し、この仕組みには依存しない。
 *
 * 履歴はテキストのみ（role + text）を保持する。画像パートは容量が大きく、チャット継続の文脈維持には
 * 本文だけで足りるため保存しない。
 */
class ConversationStore
{
    /** キャッシュキーの接頭辞。 */
    private const KEY_PREFIX = 'ai_conversation_';

    /** 履歴の保持期間（秒）。チャットドロワーの利用単位として十分な長さにする。 */
    private const LIFETIME = 3600;

    /** 保持する最大メッセージ数。古いものから切り捨て、ペイロードの肥大を防ぐ。 */
    private const MAX_MESSAGES = 24;

    /**
     * 継続トークンから会話履歴を復元する。未知のトークン・破損データは空履歴として扱う。
     *
     * @return list<Message>
     */
    public function load(string $token): array
    {
        if (!$this->isValidToken($token)) {
            return [];
        }
        $raw = $this->cacheGet(self::KEY_PREFIX . $token);
        if (!is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $messages = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = $entry['role'] ?? '';
            $text = $entry['text'] ?? '';
            if (!is_string($role) || !is_string($text) || $text === '') {
                continue;
            }
            $messages[] = $role === Message::ROLE_ASSISTANT
                ? Message::assistant(ContentPart::text($text))
                : Message::user(ContentPart::text($text));
        }

        return $messages;
    }

    /**
     * 会話履歴を保存し、次リクエストで使う継続トークンを返す。
     * トークンが未発行（null）・不正形式ならこちらで新規発行する（クライアント由来の値を
     * そのままキャッシュキーへ使わないための防御でもある）。
     *
     * @param list<Message> $messages
     */
    public function save(?string $token, array $messages): string
    {
        if ($token === null || !$this->isValidToken($token)) {
            $token = bin2hex(random_bytes(16));
        }

        $entries = [];
        foreach ($messages as $message) {
            $text = self::textOf($message);
            if ($text === '') {
                continue;
            }
            $entries[] = ['role' => $message->role, 'text' => $text];
        }
        $entries = array_slice($entries, -self::MAX_MESSAGES);

        $encoded = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // 保存できなくても生成自体は成功している。履歴なし（単発）として振る舞う。
            return $token;
        }
        $this->cachePut(self::KEY_PREFIX . $token, $encoded, self::LIFETIME);

        return $token;
    }

    /**
     * メッセージのテキストパートを連結して返す（画像パートは含めない）。
     */
    public static function textOf(Message $message): string
    {
        $texts = [];
        foreach ($message->parts as $part) {
            if ($part->type === ContentPart::TYPE_TEXT && $part->value !== '') {
                $texts[] = $part->value;
            }
        }

        return implode("\n", $texts);
    }

    /**
     * save() が発行した形式（16 バイトの hex）かどうか。
     */
    private function isValidToken(string $token): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/', $token) === 1;
    }

    /**
     * temp キャッシュからの読み出し。テストではこのシームを差し替える。
     *
     * @codeCoverageIgnore 本体キャッシュ実装への I/O 境界。ストアのロジックはシーム差し替えで検証する。
     */
    protected function cacheGet(string $key): mixed
    {
        return Cache::temp()->get($key);
    }

    /**
     * temp キャッシュへの書き込み。テストではこのシームを差し替える。
     *
     * @codeCoverageIgnore 本体キャッシュ実装への I/O 境界。ストアのロジックはシーム差し替えで検証する。
     */
    protected function cachePut(string $key, string $value, int $lifetime): void
    {
        Cache::temp()->put($key, $value, $lifetime);
    }
}
