<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers;

/**
 * AI応答に共通適用するバイト数・イベント数の上限。
 */
final class ResponseSizeLimits
{
    public const HTTP_RESPONSE_BYTES = 4 * 1024 * 1024;
    public const SSE_LINE_BYTES = 1024 * 1024;
    public const GENERATED_TEXT_BYTES = 2 * 1024 * 1024;
    public const STREAM_EVENTS = 50_000;

    public function __construct(
        public readonly int $httpResponseBytes = self::HTTP_RESPONSE_BYTES,
        public readonly int $sseLineBytes = self::SSE_LINE_BYTES,
        public readonly int $generatedTextBytes = self::GENERATED_TEXT_BYTES,
        public readonly int $streamEvents = self::STREAM_EVENTS,
    ) {
        if (
            $httpResponseBytes < 1
            || $sseLineBytes < 1
            || $generatedTextBytes < 1
            || $streamEvents < 1
        ) {
            throw new \InvalidArgumentException('AI response size limits must be positive.');
        }
    }

    public static function assertGeneratedText(string $text, int $maxBytes = self::GENERATED_TEXT_BYTES): void
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('Generated text size limit must be positive.');
        }
        if (strlen($text) > $maxBytes) {
            throw new ResponseSizeException();
        }
    }
}
