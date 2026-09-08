<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Field;

/**
 * AI 設定画面から送信された認証情報を write-only として扱う。
 *
 * 保存済みの API キーは画面へ再表示しないため、空欄は「変更なし」、入力ありは
 * 「差し替え」、削除チェック付きの空欄は「削除」として保存前の Field を補正する。
 */
final class CredentialFieldFilter
{
    /** write-only 運用の対象となる config キー。 */
    public const SECRET_KEYS = [
        'ai_api_key',
        'ai_anthropic_api_key',
    ];

    public const DELETE_SUFFIX = '_delete';

    /**
     * @param Field $post 送信値。保存前に破壊的に補正する。
     * @param Field $saved 保存済みの AI config。
     */
    public function apply(Field $post, Field $saved): void
    {
        foreach (self::SECRET_KEYS as $key) {
            $input = trim($post->get($key));
            if ($input !== '') {
                $post->set($key, $input);
                continue;
            }
            if ($post->get($key . self::DELETE_SUFFIX) === 'on') {
                $post->set($key, '');
                continue;
            }
            $post->set($key, $saved->get($key));
        }
    }
}
