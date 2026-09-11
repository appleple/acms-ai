<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\GET\AI;

use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Template;

/**
 * 管理画面へプロバイダの選択肢と認証情報の設定状況を渡す。
 *
 * モデル一覧APIにはアクセスせず、各プロバイダのローカルな isConfigured() だけを評価する。
 */
final class ProviderStatus extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $options = [];
        $statuses = [];
        $selectedId = ProviderRegistry::DEFAULT_PROVIDER;
        $registry = ProviderRegistry::withDefaults();

        try {
            $config = (new ServiceAI())->getConfig();
            $selectedId = $registry->resolve($config)->id();

            foreach ($registry->definitions() as $definition) {
                $provider = $registry->resolveById($definition['id'], $config);
                $data = [
                    'provider_id' => $definition['id'],
                    'provider_label' => $definition['label'],
                    'provider_selected' => $definition['id'] === $selectedId ? 'true' : 'false',
                    'provider_configured' => $provider->isConfigured() ? 'true' : 'false',
                ];
                $options[] = $data;
                $statuses[] = $data;
            }
        } catch (\Throwable $e) {
            // 設定読み込みに失敗しても、プロバイダ選択肢自体は表示する。
            foreach ($registry->definitions() as $definition) {
                $data = [
                    'provider_id' => $definition['id'],
                    'provider_label' => $definition['label'],
                    'provider_selected' => $definition['id'] === $selectedId ? 'true' : 'false',
                    'provider_configured' => 'false',
                ];
                $options[] = $data;
                $statuses[] = $data;
            }
        }

        return $Tpl->render([
            'provider_option' => $options,
            'provider_status' => $statuses,
            'selected_provider_id' => $selectedId,
        ]);
    }
}
