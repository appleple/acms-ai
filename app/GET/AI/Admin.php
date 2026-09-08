<?php

namespace Acms\Plugins\AI\GET\AI;

use Acms\Services\Facades\Template as Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\Plugins\AI\Services\AI\Contracts\ModelListingProvider;

class Admin extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $models = [];
        $configured = false;

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();

            $provider = ProviderRegistry::withDefaults()->resolve($config);
            // 資格情報の充足はプロバイダ内の判定（isConfigured）に閉じ、テンプレート側が
            // プロバイダ固有の config キー（ai_api_key / ai_anthropic_api_key 等）を知らずに済むようにする。
            $configured = $provider->isConfigured();
            $models = $provider instanceof ModelListingProvider ? $provider->listModels() : null;
            if ($models !== null) {
                $this->authorized = $models !== [] ? true : false;
            }
            $selectedModel = $config->get('ai_model');
            if ($selectedModel !== '') {
                $this->modelCur = $selectedModel;
            }

            if (is_array($models) && $models !== []) {
                foreach ($models as $model) {
                    $this->authorizedModels[] = [
                        'model' => $model,
                        'model_cur' => $this->modelCur
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
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
