<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\Gemini;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;

/**
 * Gemini streamGenerateContent（alt=sse）の SSE ストリームを中立の {@see StreamEvent} 列へデコードする。
 *
 * curl の書き込みコールバックは受信バイト境界が SSE 行と一致しないため、未完了行を buffer に保持しつつ
 * 完成行だけを解析する。Gemini 固有のチャンク形状（candidates[].content.parts[].text / finishReason）は
 * ここで吸収し、以降（HTTP 出力・フロント）はベンダ非依存の StreamEvent だけを扱えるようにする。
 *
 * Gemini の SSE に明示的な完了イベントは無く、最終チャンクの candidates[0].finishReason で終端を知る。
 * 継続トークンの発行はプロバイダ層（会話ストア）の責務なので、ここでは completed(null) を返す。
 *
 * @see https://ai.google.dev/api/generate-content
 */
final class GeminiStreamParser
{
    private string $buffer = '';

    /**
     * 受信バイト列を与えるたびに、完成した SSE 行を解析して StreamEvent を $onEvent へ渡す。
     *
     * @param callable(StreamEvent): void $onEvent
     */
    public function feed(string $bytes, callable $onEvent): void
    {
        $this->buffer .= $bytes;
        $lines = explode("\n", $this->buffer);
        // explode は必ず 1 要素以上を返すため array_pop は string。末尾は未完了行（次チャンクへ
        // 続く可能性）なので持ち越し、完成行だけを解析する。
        $this->buffer = array_pop($lines);

        foreach ($lines as $line) {
            $this->parseLine(rtrim($line, "\r"), $onEvent);
        }
    }

    /**
     * @param callable(StreamEvent): void $onEvent
     */
    private function parseLine(string $line, callable $onEvent): void
    {
        if (!str_starts_with($line, 'data:')) {
            return;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '') {
            return;
        }

        $chunk = json_decode($payload);
        if (!$chunk instanceof \stdClass) {
            return;
        }

        // ストリーム途中でもエラーがチャンクとして届くことがある。
        if (isset($chunk->error)) {
            $onEvent(StreamEvent::error(GeminiErrorMessage::fromError($chunk->error)));
            return;
        }

        $candidate = $this->firstCandidate($chunk);
        if ($candidate === null) {
            return;
        }

        foreach ($this->textParts($candidate) as $text) {
            $onEvent(StreamEvent::delta($text));
        }

        if (isset($candidate->finishReason) && is_string($candidate->finishReason) && $candidate->finishReason !== '') {
            $onEvent(StreamEvent::completed(null));
        }
    }

    private function firstCandidate(\stdClass $chunk): ?\stdClass
    {
        if (!isset($chunk->candidates) || !is_array($chunk->candidates) || $chunk->candidates === []) {
            return null;
        }
        $candidate = $chunk->candidates[0];

        return $candidate instanceof \stdClass ? $candidate : null;
    }

    /**
     * @return list<string>
     */
    private function textParts(\stdClass $candidate): array
    {
        if (
            !isset($candidate->content)
            || !$candidate->content instanceof \stdClass
            || !isset($candidate->content->parts)
            || !is_array($candidate->content->parts)
        ) {
            return [];
        }

        $texts = [];
        foreach ($candidate->content->parts as $part) {
            if ($part instanceof \stdClass && isset($part->text) && is_string($part->text) && $part->text !== '') {
                $texts[] = $part->text;
            }
        }

        return $texts;
    }
}
