<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;

/**
 * SSEの総受信量・単一行・生成本文・イベント数を共通上限内に保つ。
 */
final class BoundedSseStream
{
    private string $buffer = '';
    private int $receivedBytes = 0;
    private int $generatedTextBytes = 0;
    private int $eventCount = 0;

    public function __construct(private readonly ResponseSizeLimits $limits = new ResponseSizeLimits())
    {
    }

    /** @return list<string> */
    public function push(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length > $this->limits->httpResponseBytes - $this->receivedBytes) {
            throw new ResponseSizeException();
        }
        $this->receivedBytes += $length;
        $this->buffer .= $bytes;

        $lines = explode("\n", $this->buffer);
        $this->buffer = array_pop($lines);
        $this->assertLineSize($this->buffer);
        foreach ($lines as $line) {
            $this->assertLineSize($line);
        }

        return $lines;
    }

    public function assertEvent(StreamEvent $event): void
    {
        if ($this->eventCount >= $this->limits->streamEvents) {
            throw new ResponseSizeException();
        }
        $this->eventCount++;

        if ($event->type !== StreamEvent::TYPE_DELTA || $event->text === null) {
            return;
        }
        $length = strlen($event->text);
        if ($length > $this->limits->generatedTextBytes - $this->generatedTextBytes) {
            throw new ResponseSizeException();
        }
        $this->generatedTextBytes += $length;
    }

    private function assertLineSize(string $line): void
    {
        if (strlen($line) > $this->limits->sseLineBytes) {
            throw new ResponseSizeException();
        }
    }
}
