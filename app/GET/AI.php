<?php

namespace Acms\Plugins\AI\GET;

use ACMS_GET;

class AI extends ACMS_GET
{
    /** @var bool */
    protected $authorized = false;

    /** @var array<string, mixed> */
    protected $configField = [];

    /** @var string */
    protected $modelCur = '';

    /** @var string */
    protected $aiOrganizationId = '';

    /** @var string */
    protected $aiProjectId = '';

    /** @var string */
    protected $aiApiKey = '';

    /** @var list<array{model: string, model_cur: string}> */
    protected $authorizedModels = [];

    /** @var array<string, mixed> */
    protected $aiCertKey = [];

    /** @var array<string, mixed> */
    protected $aiCustomPrompt = [];
}
