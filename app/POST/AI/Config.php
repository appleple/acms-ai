<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST_Config;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\CredentialFieldFilter;

/**
 * ACMS_POST_AI_Config
 *
 * AI 機能の設定保存。コアの設定保存（ACMS_POST_Config。権限・CSRF 検証を含む）に、
 * API キー欄の write-only 補正を前置する。保存済みキーは画面へ再表示されないため、
 * 空欄の送信は「変更なし」として既存値を維持し、削除は明示チェックでのみ行う。
 */
class Config extends ACMS_POST_Config
{
    public function post()
    {
        (new CredentialFieldFilter())->apply($this->Post, (new ServicesAI())->getConfig());

        return parent::post();
    }
}
