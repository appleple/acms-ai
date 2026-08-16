<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Field;

/**
 * 管理画面の設定保存時に、API キー欄の write-only 運用を実現する純粋ロジック。
 *
 * 保存済みの API キーは画面へ再表示しない（value に出さない）ため、
 * フォームのキー欄は常に空で送信される。素通しすると保存のたびにキーが
 * 消えてしまうので、送信値に応じて保存値を決める:
 *
 * - 入力あり → 新しいキーとして保存（変更）
 * - 空欄 + 削除チェック → 空で保存（削除）
 * - 空欄のみ → 保存済みの値を維持（変更なし）
 */
final class CredentialFieldFilter
{
    /** write-only 運用の対象キー（管理画面へ再表示しない秘匿値） */
    public const SECRET_KEYS = [
        'ai_api_key',
        'ai_anthropic_api_key',
        'ai_gemini_api_key',
        'ai_compat_api_key',
    ];

    /** 削除チェックボックスの name サフィックス（config[] に含めないため保存はされない） */
    public const DELETE_SUFFIX = '_delete';

    /**
     * POST された Field を保存前に補正する。
     *
     * @param Field $post 送信された Field（破壊的に補正する）
     * @param Field $saved 保存済みの config
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
