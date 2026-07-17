<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * 会話の 1 メッセージ（プロバイダ非依存）。role とコンテンツ断片の並びを持つ。
 *
 * system 指示は会話メッセージではなく {@see GenerationRequest::$instructions} で表現する。
 */
final class Message
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @param list<ContentPart> $parts
     */
    private function __construct(
        public readonly string $role,
        public readonly array $parts,
    ) {
    }

    public static function user(ContentPart ...$parts): self
    {
        return new self(self::ROLE_USER, array_values($parts));
    }

    public static function assistant(ContentPart ...$parts): self
    {
        return new self(self::ROLE_ASSISTANT, array_values($parts));
    }
}
