<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Logging;

/**
 * 監査ログへ記事本文・プロンプトが残らないようにするための $_POST マスキング。
 *
 * a-blog cms のロガーは notice 以上のログで $_POST 全体を audit_log_req_body へ保存する。
 * コアのフィルターは api_key / token / password 等の資格情報キーをマスクする
 * （str_contains 照合のため ai_api_key 等も対象）が、AI 機能のリクエストに含まれる
 * **コンテンツ**（記事本文 article・チャット入力 input・プロンプト・画像 URL 等）は対象外で、
 * 生成に失敗してログが出るたびに本文が監査ログへ平文で蓄積されてしまう。
 *
 * 対策として、AI の POST エンドポイントの冒頭で {@see self::protectRequestBody()} を呼び、
 * 以降のリクエスト内で発生するすべてのログ（本プラグイン・コアのどちらが出すものも）が
 * マスク済みの $_POST を記録するようにする。リクエストの処理自体は Field（$this->Post）を
 * 使うため影響しない（Field はブート時に $_POST から構築済み）。
 */
final class AuditLogSanitizer
{
    private const MASK = '***MASKED***';

    /** 監査ログに残さないコンテンツ系フィールド名（str_contains・小文字比較） */
    private const CONTENT_KEY_PATTERNS = [
        'article',
        'input',
        'messages',
        'prompt',
        'image_url',
        'alreadygeneratedtags',
    ];

    /** マスク対象外の値の安全上限（巨大な値が監査ログを肥大させないように切り詰める） */
    private const MAX_STRING_LENGTH = 1000;

    /**
     * 現在のリクエストの $_POST をマスク済みへ置き換える。
     * AI の POST エンドポイントの冒頭で 1 回呼ぶ（冪等）。
     */
    public static function protectRequestBody(): void
    {
        $_POST = self::maskPostBody($_POST);
    }

    /**
     * POST ボディからコンテンツ系フィールドをマスクし、残りも安全上限で切り詰める。
     * （資格情報キーはコアのフィルターがマスクするため、ここではコンテンツに専念する）
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function maskPostBody(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isContentKey($key)) {
                $safe[$key] = self::MASK;
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = self::maskPostBody($value);
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = self::truncate($value);
                continue;
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private static function isContentKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::CONTENT_KEY_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }
}
