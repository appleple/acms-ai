<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST_Config;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\CredentialFieldFilter;
use Acms\Plugins\AI\Services\AI\Logging\AuditLogSanitizer;
use Acms\Plugins\AI\Services\AI\ModelSelectionFilter;
use Acms\Plugins\AI\Services\CmsVersionRequirement;

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
        $this->protectAuditLogRequest();
        if (!$this->isCmsVersionSupported()) {
            $this->addError(sprintf(
                'APIキーを監査ログへ残さないため、a-blog cms %s 以降へ更新してから設定を保存してください。',
                CmsVersionRequirement::MIN_VERSION
            ));
            return $this->Post;
        }

        $saved = (new ServicesAI())->getConfig();
        (new CredentialFieldFilter())->apply($this->Post, $saved);
        (new ModelSelectionFilter())->apply($this->Post, $saved);

        return parent::post();
    }

    protected function isCmsVersionSupported(): bool
    {
        return CmsVersionRequirement::currentIsSatisfied();
    }

    protected function protectAuditLogRequest(): void
    {
        (new AuditLogSanitizer())->protectRequestBody();
    }
}
