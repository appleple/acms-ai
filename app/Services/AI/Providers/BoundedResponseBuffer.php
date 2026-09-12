<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers;

/**
 * cURL書き込みコールバックで応答を上限内だけ保持する。
 */
final class BoundedResponseBuffer
{
    private string $body = '';
    private int $receivedBytes = 0;

    public function __construct(private readonly int $maxBytes = ResponseSizeLimits::HTTP_RESPONSE_BYTES)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('Response size limit must be positive.');
        }
    }

    public function append(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length > $this->maxBytes - $this->receivedBytes) {
            throw new ResponseSizeException();
        }

        $this->receivedBytes += $length;
        $this->body .= $bytes;

        return $length;
    }

    public function body(): string
    {
        return $this->body;
    }
}
