<?php

namespace Acms\Plugins\AI;

use Acms\Plugins\AI\Services\AI\EnvCredential;

class Hook
{
    /**
     * JSが更新された場合に、以前のバージョンで作られたキャッシュを使用しないようにキャッシュバスティングを行う
     * scriptタグでJSを読み込む際に、acmsのグローバル変数を経由する
     *
     * @param \Field $globalVars
     * @return void
     */
    public function extendsGlobalVars(&$globalVars)
    {
        $globalVars->set(
            'AI_JS',
            cacheBusting(
                '/' . DIR_OFFSET . 'extension/plugins/AI/bundle/acms-ai.js',
                SCRIPT_DIR . '/extension/plugins/AI/bundle/acms-ai.js'
            )
        );

        $globalVars->set(
            'AI_CSS',
            cacheBusting(
                '/' . DIR_OFFSET . 'extension/plugins/AI/bundle/acms-ai.css',
                SCRIPT_DIR . '/extension/plugins/AI/bundle/acms-ai.css'
            )
        );

        $this->setEnvFlags($globalVars);
    }

    /**
     * 認証情報が環境変数で供給されているかを、管理画面用のフラグとして渡す。
     *
     * @param \Field $globalVars
     * @return void
     */
    private function setEnvFlags(&$globalVars)
    {
        $flags = [
            'AI_OPENAI_API_KEY_FROM_ENV' => 'ai_api_key',
            'AI_OPENAI_ORGANIZATION_ID_FROM_ENV' => 'ai_organization_id',
            'AI_OPENAI_PROJECT_ID_FROM_ENV' => 'ai_project_id',
            'AI_ANTHROPIC_API_KEY_FROM_ENV' => 'ai_anthropic_api_key',
            'AI_GEMINI_API_KEY_FROM_ENV' => 'ai_gemini_api_key',
            'AI_COMPAT_API_KEY_FROM_ENV' => 'ai_compat_api_key',
        ];
        foreach ($flags as $globalVar => $configKey) {
            $globalVars->set($globalVar, EnvCredential::isSetForConfig($configKey) ? '1' : '0');
        }
    }
}
