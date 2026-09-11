<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Field;

/**
 * プロバイダ変更時に、変更前のプロバイダのモデル名が残らないよう補正する。
 */
final class ModelSelectionFilter
{
    private ProviderRegistry $registry;

    public function __construct(?ProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? ProviderRegistry::withDefaults();
    }

    public function apply(Field $post, Field $saved): void
    {
        $postedProvider = $this->registry->resolve($post)->id();
        $savedProvider = $this->registry->resolve($saved)->id();
        $post->set('ai_provider', $postedProvider);

        if ($postedProvider !== $savedProvider) {
            $post->set('ai_model', '');
            $post->set('ai_vision_model', '');
            return;
        }

        // 認証失敗などでモデル入力自体を描画できない場合も、保存済み値を失わない。
        if (!$post->isExists('ai_model')) {
            $post->set('ai_model', $saved->get('ai_model'));
        }
        if (!$post->isExists('ai_vision_model')) {
            $post->set('ai_vision_model', $saved->get('ai_vision_model'));
        }
    }
}
