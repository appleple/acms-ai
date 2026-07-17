<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;

/**
 * OpenAI Responses API の SSE ストリームを中立の {@see StreamEvent} 列へデコードする。
 *
 * curl の書き込みコールバックは受信バイト境界が SSE 行と一致しないため、未完了行を buffer に保持しつつ
 * 完成行だけを解析する。OpenAI 固有のイベント名（response.output_text.delta / response.completed）は
 * ここで吸収し、以降（HTTP 出力・フロント）はベンダ非依存の StreamEvent だけを扱えるようにする。
 */
final class ResponsesStreamParser
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
                    $onEvent(StreamEvent::delta($event->delta));
                }
                break;
            case 'response.completed':
                $onEvent(StreamEvent::completed($this->responseId($event)));
                break;
            case 'error':
                $message = (isset($event->message) && is_string($event->message))
                    ? $event->message
                    : 'エラーが発生しました。';
                $onEvent(StreamEvent::error($message));
                break;
        }
    }

    private function responseId(\stdClass $event): ?string
    {
        if (!isset($event->response) || !$event->response instanceof \stdClass) {
            return null;
        }

        return (isset($event->response->id) && is_string($event->response->id)) ? $event->response->id : null;
    }
}
