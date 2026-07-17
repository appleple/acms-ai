<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * 生成結果（プロバイダ非依存の出力）。
 *
 * `text` は抽出済みの本文（取得できなければ null）。`raw` はプロバイダの生応答（デバッグ／
 * 追加解析用）。`continuationToken` は次リクエストで会話を継続するための不透明トークン。
 */
final class GenerationResult
{
    /**
     * @param string|null $text 抽出済み本文
     * @param mixed $raw プロバイダの生応答
     * @param string|null $continuationToken 会話継続トークン
     * @param string|null $finishReason 終了理由（あれば）
     * @param TokenUsage|null $usage トークン使用量（取得できれば）
     * @param string|null $errorMessage 失敗時の利用者向けメッセージ（プロバイダが分かりやすい理由へ整形。成功時は null）
     */
    public function __construct(
        public readonly ?string $text,
        public readonly mixed $raw = null,
        public readonly ?string $continuationToken = null,
        public readonly ?string $finishReason = null,
        public readonly ?TokenUsage $usage = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
