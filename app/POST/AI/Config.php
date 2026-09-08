<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST_Config;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\CredentialFieldFilter;

/**
 * AI 設定の保存処理。
 *
 * コアの権限・CSRF 検証を含む設定保存処理へ委譲する前に、画面へ再表示しない
 * API キーの維持・差し替え・明示削除を補正する。
 */
class Config extends ACMS_POST_Config
{
    public function post()
    {
        (new CredentialFieldFilter())->apply($this->Post, (new ServicesAI())->getConfig());

        return parent::post();
    }
}
