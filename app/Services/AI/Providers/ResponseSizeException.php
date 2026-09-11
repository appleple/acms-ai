<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers;

final class ResponseSizeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI応答が許容サイズを超えました。');
    }
}
