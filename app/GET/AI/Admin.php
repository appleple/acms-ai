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

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();

            $provider = ProviderRegistry::withDefaults()->resolve($config);
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
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
