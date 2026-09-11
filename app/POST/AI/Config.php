<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST_Config;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\CredentialFieldFilter;
use Acms\Plugins\AI\Services\AI\ModelSelectionFilter;

/**
 * AI 設定の保存処理。
 *
 * コアの権限・CSRF 検証を含む設定保存処理へ委譲する前に、画面へ再表示しない
 * API キーと、プロバイダ変更時のモデル選択を補正する。
 */
class Config extends ACMS_POST_Config
{
    public function post()
    {
        $saved = (new ServicesAI())->getConfig();
        (new CredentialFieldFilter())->apply($this->Post, $saved);
        (new ModelSelectionFilter())->apply($this->Post, $saved);

        return parent::post();
    }
}
