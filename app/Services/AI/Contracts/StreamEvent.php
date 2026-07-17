<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * ストリーミング生成の中立イベント（プロバイダ非依存）。
 *
 * プロバイダ実装がベンダ固有のワイヤ形式（OpenAI Responses API の SSE イベント等）を
 * この共通形へデコードして emit する。HTTP 出力（SSE 整形）や画面表示の責務は消費側が持ち、
 * 消費側はベンダ固有のイベント名・構造に依存しない。
 */
final class StreamEvent
{
    /** 本文の増分。 */
    public const TYPE_DELTA = 'delta';

    /** 生成完了（会話継続トークンを伴う）。 */
    public const TYPE_COMPLETED = 'completed';

    /** エラー。 */
    public const TYPE_ERROR = 'error';

    private function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $continuationToken = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function delta(string $text): self
    {
        return new self(self::TYPE_DELTA, $text);
    }

    public static function completed(?string $continuationToken): self
    {
        return new self(self::TYPE_COMPLETED, null, $continuationToken);
    }

    public static function error(string $message): self
    {
        return new self(self::TYPE_ERROR, null, null, $message);
    }
}
