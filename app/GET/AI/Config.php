<?php

namespace Acms\Plugins\AI\GET\AI;

use Acms\Services\Facades\Template as Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;

class Config extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();
            $cert = $ServiceAI->getCertification($config);
            $this->configField = Tpl::buildField($config, $Tpl);

            if (
                isset($cert['ai_api_key']) &&
                isset($cert['ai_model']) &&
                $ServiceAI->availableModel($cert['ai_model']) !== null
            ) {
                $this->authorized = true;
                $this->configField = Tpl::buildField($config, $Tpl);
            }
        } catch (\Exception $e) {
        }

        $obj = array_merge(
            [
                'authorized' => $this->authorized ? 'true' : 'false',
            ],
            $this->configField
        );

        return $Tpl->render($obj);
    }
}
