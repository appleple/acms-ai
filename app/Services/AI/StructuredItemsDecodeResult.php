<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

/**
 * タイトル・タグ候補の構造化応答を検証した結果。
 */
final class StructuredItemsDecodeResult
{
    /**
     * @param list<array{content: string}> $items
     */
    private function __construct(
        public readonly array $items,
        public readonly ?string $failureReason,
        public readonly ?int $jsonErrorCode,
    ) {
    }

    /**
     * @param list<array{content: string}> $items
     */
    public static function success(array $items): self
    {
        return new self($items, null, null);
    }

    public static function failure(string $reason, ?int $jsonErrorCode = null): self
    {
        return new self([], $reason, $jsonErrorCode);
    }

    public function succeeded(): bool
    {
        return $this->failureReason === null;
    }
}
