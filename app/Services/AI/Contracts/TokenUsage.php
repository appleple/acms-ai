<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * 生成 1 回あたりのトークン使用量（プロバイダ非依存）。
 *
 * 各プロバイダの生応答（OpenAI Responses API の usage.input_tokens など）を
 * この共通形へ写して {@see GenerationResult} に載せる。取得できない場合は null（未設定）とする。
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
    ) {
    }
}
