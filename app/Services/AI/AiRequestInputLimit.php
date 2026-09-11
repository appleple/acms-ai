<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;

/**
 * AI 生成へ渡す入力サイズの上限を判定する。
 *
 * 文字コードに依存せず、HTTP・プロバイダへ実際に渡すデータ量に近いバイト数で数える。
 */
final class AiRequestInputLimit
{
    private const DEFAULT_MAX_BYTES = 262144;

    public function __construct(private readonly int $maxBytes)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('AI request input limit must be greater than zero.');
        }
    }

    public static function fromConfig(): self
    {
        $value = config('ai_request_max_input_bytes', self::DEFAULT_MAX_BYTES);
        $maxBytes = is_numeric($value) ? (int) $value : self::DEFAULT_MAX_BYTES;

        return new self($maxBytes > 0 ? $maxBytes : self::DEFAULT_MAX_BYTES);
    }

    public function accepts(string ...$values): bool
    {
        $remaining = $this->maxBytes;
        foreach ($values as $value) {
            $length = strlen($value);
            if ($length > $remaining) {
                return false;
            }
            $remaining -= $length;
        }

        return true;
    }

    /**
     * instructions・継続ID・送信メッセージの文字列を、入口とプロバイダで同じ規則により合計する。
     *
     * @param list<\Acms\Plugins\AI\Services\AI\Contracts\Message>|null $messages
     */
    public function acceptsRequest(
        GenerationRequest $request,
        ?array $messages = null,
        bool $includeContinuationToken = true,
    ): bool {
        $values = [];
        if ($request->instructions !== null) {
            $values[] = $request->instructions;
        }
        if ($includeContinuationToken && $request->continuationToken !== null) {
            $values[] = $request->continuationToken;
        }
        foreach ($messages ?? $request->messages as $message) {
            foreach ($message->parts as $part) {
                // バイナリは MediaImageLoader の専用上限で検査済み。
                if ($part->type !== ContentPart::TYPE_IMAGE_DATA) {
                    $values[] = $part->value;
                }
            }
        }

        return $this->accepts(...$values);
    }
}
