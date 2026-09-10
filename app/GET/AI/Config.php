<?php

namespace Acms\Plugins\AI\GET\AI;

use Acms\Services\Facades\Template as Tpl;
use Template;
use ACMS_Corrector;
use Acms\Plugins\AI\GET\AI;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\EntryAiSettings;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;

class Config extends AI
{
    public function get()
    {
        $Tpl = new Template($this->tpl, new ACMS_Corrector());
        $entryEnabled = false;
        $titleEnabled = false;
        $tagEnabled = false;

        try {
            $ServiceAI = new ServiceAI();
            $config = $ServiceAI->getConfig();
            $this->configField = Tpl::buildField($config, $Tpl);

            // 資格情報が揃い、モデルが選択済みなら AI 機能を有効表示する。プロバイダ固有の資格情報
            // （OpenAI の organization/project 等）の充足判定は provider->isConfigured() に閉じ、
            // 許可リストによるモデル妥当性検証は列挙を行う管理画面（GET/AI/Admin → listModels()）の責務に集約する。
            $provider = ProviderRegistry::withDefaults()->resolve($config);
            $model = $config->get('ai_model');
            if ($provider->isConfigured() && $model !== '') {
                $this->authorized = true;
            }

            $settings = new EntryAiSettings($config);
            $entryEnabled = $settings->entryEnabled();
            $titleEnabled = $settings->titleEnabled();
            $tagEnabled = $settings->tagEnabled();
        } catch (\Throwable $e) {
        }

        $obj = array_merge(
            $this->configField,
            [
                'authorized' => $this->authorized ? 'true' : 'false',
                'entry_ai_enabled' => $entryEnabled ? 'true' : 'false',
                'title_enabled' => $titleEnabled ? 'true' : 'false',
                'tag_enabled' => $tagEnabled ? 'true' : 'false',
            ]
        );

        return $Tpl->render($obj);
    }
}
