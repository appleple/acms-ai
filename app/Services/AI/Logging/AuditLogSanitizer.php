<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Logging;

use Acms\Services\Facades\Application;

/**
 * 監査ログへ記事本文・プロンプト・APIキーが残らないようにするリクエスト保護。
 *
 * a-blog cms のロガーは notice 以上のログで $_POST 全体を audit_log_req_body へ保存する。
 * コアのフィルターは api_key / token / password 等の資格情報キーをマスクする
 * （str_contains 照合のため ai_api_key 等も対象）が、AI 機能のリクエストに含まれる
 * **コンテンツ**（記事本文 article・チャット入力 input・プロンプト・画像 URL 等）は対象外で、
 * 生成に失敗してログが出るたびに本文が監査ログへ平文で蓄積されてしまう。
 * また、3.2.28以前のコンフィグ保存ログはField全体をcontextへ渡すため、本クラスだけでは
 * APIキーのcontext漏えいを防げない。設定保存側は該当バージョンで処理自体を拒否する。
 *
 * 対策として、AI の POST エンドポイントの冒頭で {@see self::protectRequestBody()} を呼び、
 * コアのログフィルターへ AI 固有の機密キーを登録する。これにより $_POST 自体は変更せず、
 * 以降のリクエスト内で発生するすべてのログ（本プラグイン・コアのどちらが出すものも）を
 * 保護できる。登録 API がない旧バージョンでは、互換用に $_POST のマスクへフォールバックする。
 */
final class AuditLogSanitizer
{
    private const MASK = '***MASKED***';

    /** 監査ログに残さない機密フィールド名（str_contains・小文字比較） */
    private const SENSITIVE_KEY_PATTERNS = [
        'article',
        'input',
        'messages',
        'prompt',
        'image_url',
        'alreadygeneratedtags',
        'previousresponseid',
        // AI設定の4プロバイダすべて（ai_api_key / ai_*_api_key）を含む。
        'api_key',
    ];

    /** マスク対象外の値の安全上限（巨大な値が監査ログを肥大させないように切り詰める） */
    private const MAX_STRING_LENGTH = 1000;

    private ?object $filter;

    public function __construct(?object $filter = null)
    {
        $this->filter = $filter;
    }

    /**
     * コアのログフィルターへ AI 固有の機密キーを登録する。
     * 登録 API がない環境では、現在の $_POST をマスク済みへ置き換える（冪等）。
     */
    public function protectRequestBody(): void
    {
        $filter = $this->filter ?? $this->resolveCoreFilter();
        if ($filter !== null && $this->registerWithCoreFilter($filter)) {
            return;
        }

        $_POST = self::maskPostBody($_POST);
    }

    /**
     * POST ボディからコンテンツ・資格情報フィールドをマスクし、残りも安全上限で切り詰める。
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function maskPostBody(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
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

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
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

    private function resolveCoreFilter(): ?object
    {
        try {
            $filter = Application::make('acms-logger-filter');
            return is_object($filter) ? $filter : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function registerWithCoreFilter(object $filter): bool
    {
        if (!is_callable([$filter, 'registerSensitiveKeys'])) {
            return false;
        }

        try {
            $filter->registerSensitiveKeys('field', self::SENSITIVE_KEY_PATTERNS);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
