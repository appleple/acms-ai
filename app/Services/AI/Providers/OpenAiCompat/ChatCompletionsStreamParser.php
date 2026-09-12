<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\BoundedSseStream;

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
    /** completed / error を受け取ったか（終端後のイベント発行防止用）。 */
    private bool $terminated = false;

    public function __construct(private readonly BoundedSseStream $stream = new BoundedSseStream())
    {
    }

    /**
     * 受信バイト列を与えるたびに、完成した SSE 行を解析して StreamEvent を $onEvent へ渡す。
     *
     * @param callable(StreamEvent): void $onEvent
     */
    public function feed(string $bytes, callable $onEvent): void
    {
        foreach ($this->stream->push($bytes) as $line) {
            $this->parseLine(rtrim($line, "\r"), $onEvent);
        }
    }

    /**
     * @param callable(StreamEvent): void $onEvent
     */
    private function parseLine(string $line, callable $onEvent): void
    {
        if ($this->terminated) {
            return;
        }
        if (!str_starts_with($line, 'data:')) {
            return;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '') {
            return;
        }
        if ($payload === '[DONE]') {
            $this->terminated = true;
            $this->emit(StreamEvent::completed(null), $onEvent);
            return;
        }

        $chunk = json_decode($payload);
        if (!$chunk instanceof \stdClass) {
            return;
        }

        // ストリーム途中でもエラーがチャンクとして届くことがある。
        if (isset($chunk->error)) {
            $this->terminated = true;
            $this->emit(StreamEvent::error(OpenAiCompatErrorMessage::fromError($chunk->error)), $onEvent);
            return;
        }

        $text = $this->deltaContent($chunk);
        if ($text !== null && $text !== '') {
            $this->emit(StreamEvent::delta($text), $onEvent);
        }
    }

    /** @param callable(StreamEvent): void $onEvent */
    private function emit(StreamEvent $event, callable $onEvent): void
    {
        $this->stream->assertEvent($event);
        $onEvent($event);
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
