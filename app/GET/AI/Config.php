<?php

namespace Acms\Plugins\AI\GET\AI;

use Tpl;
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

            // API キーとモデルが設定済みなら AI 機能を有効表示する。モデルの妥当性検証（許可リスト）は
            // プロバイダ固有のため、認証を行う管理画面（GET/AI/Admin → provider->authenticate()）の責務に集約する。
            $apiKey = $cert['ai_api_key'];
            $model = $cert['ai_model'];
            if (is_string($apiKey) && $apiKey !== '' && is_string($model) && $model !== '') {
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
