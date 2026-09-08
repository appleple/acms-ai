<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\Gemini;

/**
 * Gemini のエラー応答（{ code, message, status }）を利用者向けの日本語メッセージへ写す。
 *
 * Gemini 固有のエラー status（Google API 共通の gRPC ステータス文字列）をここで吸収し、
 * 消費側・画面には「何をすればよいか」が分かる理由を渡す。生成・ストリーミング双方から使う
 * 単一の変換点。判別できないものは汎用メッセージへフォールバックする。
 *
 * @see https://ai.google.dev/gemini-api/docs/troubleshooting
 */
final class GeminiErrorMessage
{
    /**
     * Gemini のエラーオブジェクト（json_decode 済み・想定は \stdClass）から日本語メッセージを返す。
     */
    public static function fromError(mixed $error): string
    {
        $status = ($error instanceof \stdClass && isset($error->status) && is_string($error->status))
            ? $error->status
            : '';

        return match ($status) {
            'UNAUTHENTICATED'
                => 'API キーが正しくないか権限がありません。設定をご確認ください。',
            'PERMISSION_DENIED'
                => 'この API キーには要求した操作の権限がありません。設定をご確認ください。',
            'NOT_FOUND'
                => '選択中のモデルが利用できません。モデル設定をご確認ください。',
            'RESOURCE_EXHAUSTED'
                => '利用枠（クォータ）の超過またはリクエストの集中です。しばらく待ってから再試行してください。',
            'INVALID_ARGUMENT', 'FAILED_PRECONDITION'
                => 'リクエストが不正です。モデル名や設定をご確認ください。',
            'UNAVAILABLE', 'DEADLINE_EXCEEDED'
                => 'Gemini 側が混雑しています。時間をおいて再試行してください。',
            'INTERNAL'
                => 'Gemini 側で一時的なエラーが発生しました。時間をおいて再試行してください。',
            default
                => 'AI からの応答取得に失敗しました。設定や Gemini の状態をご確認ください。',
        };
    }

    /**
     * 正常な HTTP 応答に含まれる promptFeedback / finishReason を失敗理由へ変換する。
     * STOP または終了理由なしはエラーではないため null を返す。
     */
    public static function fromResponse(mixed $response): ?string
    {
        if (!$response instanceof \stdClass) {
            return null;
        }

        if (isset($response->promptFeedback) && $response->promptFeedback instanceof \stdClass) {
            $reason = $response->promptFeedback->blockReason ?? null;
            if (is_string($reason) && $reason !== '' && $reason !== 'BLOCK_REASON_UNSPECIFIED') {
                return self::fromBlockReason($reason);
            }
        }

        if (!isset($response->candidates) || !is_array($response->candidates) || $response->candidates === []) {
            return null;
        }

        $candidate = $response->candidates[0];
        if (!$candidate instanceof \stdClass) {
            return null;
        }

        $reason = $candidate->finishReason ?? null;
        if (!is_string($reason) || $reason === '' || $reason === 'STOP') {
            return null;
        }

        return match ($reason) {
            'MAX_TOKENS'
                => 'AI の応答が出力上限に達しました。内容を短くして再試行してください。',
            'SAFETY', 'IMAGE_SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'
                => '安全性の判定により AI の応答がブロックされました。入力内容を見直してください。',
            'RECITATION'
                => '引用・著作物に関する判定により AI の応答が停止しました。入力内容を見直してください。',
            'LANGUAGE'
                => '対応していない言語として AI の応答が停止しました。入力言語を見直してください。',
            default
                => 'AI が応答を完了できませんでした。入力内容を見直して再試行してください。',
        };
    }

    private static function fromBlockReason(string $reason): string
    {
        return match ($reason) {
            'SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT'
                => '安全性の判定により入力がブロックされました。入力内容を見直してください。',
            default
                => 'AI が入力を処理できませんでした。入力内容を見直して再試行してください。',
        };
    }
}
