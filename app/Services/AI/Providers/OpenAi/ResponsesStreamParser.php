<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\BoundedSseStream;

/**
 * OpenAI Responses API の SSE ストリームを中立の {@see StreamEvent} 列へデコードする。
 *
 * curl の書き込みコールバックは受信バイト境界が SSE 行と一致しないため、未完了行を buffer に保持しつつ
 * 完成行だけを解析する。OpenAI 固有のイベント名（response.output_text.delta / response.completed）は
 * ここで吸収し、以降（HTTP 出力・フロント）はベンダ非依存の StreamEvent だけを扱えるようにする。
 */
final class ResponsesStreamParser
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
        if ($payload === '' || $payload === '[DONE]') {
            return;
        }

        $event = json_decode($payload);
        if (!$event instanceof \stdClass || !isset($event->type) || !is_string($event->type)) {
            return;
        }

        switch ($event->type) {
            case 'response.output_text.delta':
                if (isset($event->delta) && is_string($event->delta)) {
                    $this->emit(StreamEvent::delta($event->delta), $onEvent);
                }
                break;
            case 'response.completed':
                $this->emit(StreamEvent::completed($this->responseId($event)), $onEvent);
                break;
            case 'error':
                // OpenAI 固有の code/type を利用者向けメッセージへ写す（生成側と同一の変換点）。
                $this->emit(StreamEvent::error(OpenAiErrorMessage::fromError($event)), $onEvent);
                break;
        }
    }

    /** @param callable(StreamEvent): void $onEvent */
    private function emit(StreamEvent $event, callable $onEvent): void
    {
        $this->stream->assertEvent($event);
        $onEvent($event);
    }

    private function responseId(\stdClass $event): ?string
    {
        if (!isset($event->response) || !$event->response instanceof \stdClass) {
            return null;
        }

        return (isset($event->response->id) && is_string($event->response->id)) ? $event->response->id : null;
    }
}
