<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\Anthropic;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\BoundedSseStream;

/**
 * Anthropic Messages API の SSE ストリームを中立の {@see StreamEvent} 列へデコードする。
 *
 * curl の書き込みコールバックは受信バイト境界が SSE 行と一致しないため、未完了行を buffer に保持しつつ
 * 完成行だけを解析する。Anthropic 固有のイベント名（content_block_delta / message_stop / error）は
 * ここで吸収し、以降（HTTP 出力・フロント）はベンダ非依存の StreamEvent だけを扱えるようにする。
 *
 * Anthropic の SSE は `event: <名前>` 行と `data: {...}` 行の組で届くが、data ペイロード自身が
 * type フィールドを持つため、data 行だけを解析すれば十分（event 行は読まない）。
 * message_stop は完了通知のみで会話継続の識別子を持たない。継続トークンの発行は
 * プロバイダ層（会話ストア）の責務なので、ここでは completed(null) を返す。
 *
 * @see https://docs.anthropic.com/en/api/messages-streaming
 */
final class AnthropicStreamParser
{
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
        if (!str_starts_with($line, 'data:')) {
            return;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '') {
            return;
        }

        $event = json_decode($payload);
        if (!$event instanceof \stdClass || !isset($event->type) || !is_string($event->type)) {
            return;
        }

        switch ($event->type) {
            case 'content_block_delta':
                $text = $this->deltaText($event);
                if ($text !== null) {
                    $this->emit(StreamEvent::delta($text), $onEvent);
                }
                break;
            case 'message_stop':
                $this->emit(StreamEvent::completed(null), $onEvent);
                break;
            case 'error':
                // Anthropic 固有のエラー type を利用者向けメッセージへ写す（生成側と同一の変換点）。
                $this->emit(StreamEvent::error(AnthropicErrorMessage::fromError($event->error ?? null)), $onEvent);
                break;
        }
    }

    /** @param callable(StreamEvent): void $onEvent */
    private function emit(StreamEvent $event, callable $onEvent): void
    {
        $this->stream->assertEvent($event);
        $onEvent($event);
    }

    /**
     * content_block_delta から本文増分を取り出す。テキスト以外の増分（ツール入力等）は null。
     */
    private function deltaText(\stdClass $event): ?string
    {
        if (!isset($event->delta) || !$event->delta instanceof \stdClass) {
            return null;
        }
        $delta = $event->delta;
        if (!isset($delta->type) || $delta->type !== 'text_delta') {
            return null;
        }

        return (isset($delta->text) && is_string($delta->text)) ? $delta->text : null;
    }
}
