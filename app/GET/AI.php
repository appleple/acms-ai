<?php

namespace Acms\Plugins\AI\GET;

use ACMS_GET;
use Zend\Filter\Boolean;

class AI extends ACMS_GET
{
    protected $authorized = false;
    protected $configField = [];
    protected $modelCur = '';
    protected $aiOrganizationId = '';
    protected $aiProjectId = '';
    protected $aiApiKey = '';
    protected $authorizedModels = [];
    protected $aiCertKey = [];
    protected $aiCustomPrompt = [];
}
