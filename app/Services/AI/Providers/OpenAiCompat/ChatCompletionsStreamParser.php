<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;

/**
 * Chat Completions（stream=true）の SSE ストリームを中立の {@see StreamEvent} 列へデコードする。
 *
 * curl の書き込みコールバックは受信バイト境界が SSE 行と一致しないため、未完了行を buffer に保持しつつ
 * 完成行だけを解析する。Chat Completions 固有のチャンク形状（choices[].delta.content / [DONE]）は
 * ここで吸収し、以降（HTTP 出力・フロント）はベンダ非依存の StreamEvent だけを扱えるようにする。
 *
 * 終端は `data: [DONE]` で通知される。会話継続の識別子はワイヤに無く、継続トークンの発行は
 * プロバイダ層（会話ストア）の責務なので、ここでは completed(null) を返す。
 */
final class ChatCompletionsStreamParser
{
    private string $buffer = '';

    /** [DONE] を受け取ったか（重複 completed の防止用）。 */
    private bool $done = false;

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
        if ($payload === '[DONE]') {
            if (!$this->done) {
                $this->done = true;
                $onEvent(StreamEvent::completed(null));
            }
            return;
        }

        $chunk = json_decode($payload);
        if (!$chunk instanceof \stdClass) {
            return;
        }

        // ストリーム途中でもエラーがチャンクとして届くことがある。
        if (isset($chunk->error)) {
            $onEvent(StreamEvent::error(OpenAiCompatErrorMessage::fromError($chunk->error)));
            return;
        }

        $text = $this->deltaContent($chunk);
        if ($text !== null && $text !== '') {
            $onEvent(StreamEvent::delta($text));
        }
    }

    /**
     * choices[0].delta.content を取り出す。無ければ null。
     */
    private function deltaContent(\stdClass $chunk): ?string
    {
        if (!isset($chunk->choices) || !is_array($chunk->choices) || $chunk->choices === []) {
            return null;
        }
        $choice = $chunk->choices[0];
        if (!$choice instanceof \stdClass || !isset($choice->delta) || !$choice->delta instanceof \stdClass) {
            return null;
        }
        $delta = $choice->delta;

        return (isset($delta->content) && is_string($delta->content)) ? $delta->content : null;
    }
}
