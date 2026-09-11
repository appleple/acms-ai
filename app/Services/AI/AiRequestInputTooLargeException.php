<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

final class AiRequestInputTooLargeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI に送信する入力が大きすぎます。本文または入力内容を短くしてください。');
    }
}
