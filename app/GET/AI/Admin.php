<?php

namespace Acms\Plugins\AI\GET\AI;

use Acms\Services\Facades\Template as Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\ModelFilter;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\Plugins\AI\Services\AI\Contracts\ModelListingProvider;

class Admin extends AI
{
    /**
     * プロバイダ id → 表示名（管理画面のプロバイダ select の表記に合わせる）
     */
    private const PROVIDER_LABELS = [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic (Claude)',
        'gemini' => 'Google (Gemini)',
        'compat' => 'OpenAI互換',
    ];

    /**
     * リクエスト内の listModels 結果のメモ（プロバイダ id → モデル一覧）。
     * 本モジュールはテンプレートに複数配置される（モデル・画像解析モデル・設定状況）ため、
     * 配置数ぶんプロバイダ API を叩き直さないようにする。
     *
     * @var array<string, list<string>|null>
     */
    private static array $modelsMemo = [];

    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $models = [];
        $configured = false;
        $providerStatus = [];
        $visionModels = [];

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();

            $registry = ProviderRegistry::withDefaults();
            // 選択中以外も含む全プロバイダの設定状況。管理画面でプロバイダを切り替えなくても
            // どのプロバイダに資格情報が設定済みかを一覧できるようにする
            foreach ($registry->ids() as $id) {
                $providerStatus[] = [
                    'provider_label' => self::PROVIDER_LABELS[$id] ?? $id,
                    'provider_configured' => $registry->resolveById($id, $config)->isConfigured() ? 'true' : 'false',
                ];
            }

            $provider = $registry->resolve($config);
            // 資格情報の充足はプロバイダ内の判定（isConfigured）に閉じ、テンプレート側が
            // プロバイダ固有の config キー（ai_api_key / ai_anthropic_api_key 等）を知らずに済むようにする。
            $configured = $provider->isConfigured();
            if (array_key_exists($provider->id(), self::$modelsMemo)) {
                $models = self::$modelsMemo[$provider->id()];
            } else {
                $models = $provider instanceof ModelListingProvider ? $provider->listModels() : null;
                self::$modelsMemo[$provider->id()] = $models;
            }
            if ($models !== null) {
                $this->authorized = $models !== [] ? true : false;
            }
            $selectedModel = $config->get('ai_model');
            if ($selectedModel !== '') {
                $this->modelCur = $selectedModel;
            }
            $visionModelCur = $config->get('ai_vision_model');

            if (is_array($models) && $models !== []) {
                // 選択肢の絞り込みは config（既定は config.system.yaml）で行う。
                // 画像解析（vision）用は別パターンを指定でき、未指定なら通常と同じ一覧を使う
                $textPatterns = $config->get('ai_' . $provider->id() . '_allowed_models');
                $visionPatterns = $config->get('ai_' . $provider->id() . '_allowed_vision_models');
                $textList = ModelFilter::filter($models, $textPatterns);
                $visionList = $visionPatterns !== '' ? ModelFilter::filter($models, $visionPatterns) : $textList;
                foreach ($textList as $model) {
                    $this->authorizedModels[] = [
                        'model' => $model,
                        'model_cur' => $this->modelCur,
                    ];
                }
                foreach ($visionList as $model) {
                    $visionModels[] = [
                        'model' => $model,
                        'vision_model_cur' => $visionModelCur,
                    ];
                }
            }

            $this->configField = Tpl::buildField($config, $Tpl);
        } catch (\Exception $e) {
        }

        $obj = array_merge(
            ['model' => $this->authorizedModels],
            ['vision_model' => $visionModels],
            ['provider_status' => $providerStatus],
            ['authorized' => $this->authorized ? 'true' : 'false'],
            ['configured' => $configured ? 'true' : 'false'],
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
