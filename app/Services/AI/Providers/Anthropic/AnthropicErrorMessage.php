<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\Anthropic;

/**
 * Anthropic のエラー応答（{ type, message }）を利用者向けの日本語メッセージへ写す。
 *
 * Anthropic 固有のエラー type をここで吸収し、消費側・画面には「何をすればよいか」が分かる理由を渡す。
 * 生成・ストリーミング双方から使う単一の変換点。判別できないものは汎用メッセージへフォールバックする。
 *
 * @see https://docs.anthropic.com/en/api/errors
 */
final class AnthropicErrorMessage
{
    /**
     * Anthropic のエラーオブジェクト（json_decode 済み・想定は \stdClass）から日本語メッセージを返す。
     */
    public static function fromError(mixed $error): string
    {
        $type = ($error instanceof \stdClass && isset($error->type) && is_string($error->type)) ? $error->type : '';

        return match ($type) {
            'authentication_error'
                => 'API キーが正しくないか権限がありません。設定をご確認ください。',
            'permission_error'
                => 'この API キーには要求した操作の権限がありません。設定をご確認ください。',
            'not_found_error'
                => '選択中のモデルが利用できません。モデル設定をご確認ください。',
            'rate_limit_error'
                => 'リクエストが集中しています。しばらく待ってから再試行してください。',
            'overloaded_error'
                => 'Anthropic 側が混雑しています。時間をおいて再試行してください。',
            'api_error'
                => 'Anthropic 側で一時的なエラーが発生しました。時間をおいて再試行してください。',
            'invalid_request_error'
                => 'リクエストが不正です。モデル名や設定をご確認ください。',
            default
                => 'AI からの応答取得に失敗しました。設定や Anthropic の状態をご確認ください。',
        };
    }
}
