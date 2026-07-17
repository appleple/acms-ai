<?php

namespace Acms\Plugins\AI\GET\AI;

use Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;

class Admin extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $models = [];

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();
            $cert = $ServiceAI->getCertification($config);

            $provider = ProviderRegistry::withDefaults()->resolve($config);
            $models = $provider->authenticate();
            if ($models !== null) {
                $this->authorized = $models !== [] ? true : false;
            }
            if ($cert['ai_model']) {
                $this->modelCur = $cert['ai_model'];
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
