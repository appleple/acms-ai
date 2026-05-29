<?php

namespace Acms\Plugins\AI;

class Hook
{
    /**
     * JSが更新された場合に、以前のバージョンで作られたキャッシュを使用しないようにキャッシュバスティングを行う
     * scriptタグでJSを読み込む際に、acmsのグローバル変数を経由する
     *
     * @param \Field &$globalVars
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
    }
}
