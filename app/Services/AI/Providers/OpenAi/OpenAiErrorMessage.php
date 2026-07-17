<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

/**
 * OpenAI のエラー応答（{ code, type, message }）を利用者向けの日本語メッセージへ写す。
 *
 * OpenAI 固有の code/type をここで吸収し、消費側・画面には「何をすればよいか」が分かる理由を渡す。
 * 生成・ストリーミング双方から使う単一の変換点。判別できないものは汎用メッセージへフォールバックする。
 */
final class OpenAiErrorMessage
{
    /**
     * OpenAI のエラーオブジェクト（json_decode 済み・想定は \stdClass）から日本語メッセージを返す。
     */
    public static function fromError(mixed $error): string
    {
        $code = ($error instanceof \stdClass && isset($error->code) && is_string($error->code)) ? $error->code : '';
        $type = ($error instanceof \stdClass && isset($error->type) && is_string($error->type)) ? $error->type : '';

        return match (true) {
            $code === 'insufficient_quota'
                => 'OpenAI の利用枠（クォータ）を超過しています。プランと請求情報をご確認ください。',
            $code === 'invalid_api_key', $type === 'authentication_error'
                => 'API キーが正しくないか権限がありません。設定をご確認ください。',
            $code === 'model_not_found'
                => '選択中のモデルが利用できません。モデル設定をご確認ください。',
            $code === 'rate_limit_exceeded', $type === 'rate_limit_error'
                => 'リクエストが集中しています。しばらく待ってから再試行してください。',
            $type === 'server_error'
                => 'OpenAI 側で一時的なエラーが発生しました。時間をおいて再試行してください。',
            default
                => 'AI からの応答取得に失敗しました。設定や OpenAI の状態をご確認ください。',
        };
    }
}
