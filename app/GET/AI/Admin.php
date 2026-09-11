<?php

namespace Acms\Plugins\AI\GET\AI;

use Acms\Services\Facades\Template as Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\ModelListFilter;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\Plugins\AI\Services\AI\Contracts\ModelListingProvider;
use Acms\Plugins\AI\Services\AI\Contracts\ManualModelProvider;

class Admin extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $models = [];
        $configured = false;
        $manualModel = false;

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();

            $provider = ProviderRegistry::withDefaults()->resolve($config);
            // 資格情報の充足はプロバイダ内の判定（isConfigured）に閉じ、テンプレート側が
            // プロバイダ固有の config キー（ai_api_key / ai_anthropic_api_key 等）を知らずに済むようにする。
            $configured = $provider->isConfigured();
            $manualModel = $provider instanceof ManualModelProvider;
            $models = !$manualModel && $provider instanceof ModelListingProvider ? $provider->listModels() : null;
            if ($manualModel) {
                // OpenAI 互換 API は /models を必須としない。モデル名は接続先の仕様を確認して手入力する。
                $this->authorized = true;
            } elseif (is_array($models)) {
                $models = (new ModelListFilter($config))->apply($provider->id(), $models);
                $this->authorized = $models !== [] ? true : false;
            }
            $selectedModel = $config->get('ai_model');
            if ($selectedModel !== '') {
                $this->modelCur = $selectedModel;
            }
            $visionModel = $config->get('ai_vision_model');

            if (is_array($models) && $models !== []) {
                foreach ($models as $model) {
                    $this->authorizedModels[] = [
                        'model' => $model,
                        'model_cur' => $this->modelCur,
                        'vision_model_cur' => $visionModel,
                    ];
                }
            }

            $this->configField = Tpl::buildField($config, $Tpl);
        } catch (\Exception $e) {
        }

        $obj = array_merge(
            ['model' => $this->authorizedModels],
            ['authorized' => $this->authorized ? 'true' : 'false'],
            ['configured' => $configured ? 'true' : 'false'],
            ['manualModel' => $manualModel ? 'true' : 'false'],
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
