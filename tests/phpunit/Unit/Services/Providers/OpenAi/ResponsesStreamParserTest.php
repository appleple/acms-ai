<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\ResponsesStreamParser;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * OpenAI Responses SSE → 中立 {@see StreamEvent} 列へのデコードを固定する。
 *
 * OpenAI 固有のイベント名（response.output_text.delta / response.completed / error）を吸収し、
 * チャンク境界が SSE 行の途中で切れても正しく復元できること、data 以外の行を無視することを保証する。
 */
final class ResponsesStreamParserTest extends TestCase
{
    /**
     * @return list<StreamEvent>
     */
    private function parse(string ...$chunks): array
    {
        $events = [];
        $parser = new ResponsesStreamParser();
        foreach ($chunks as $chunk) {
            $parser->feed($chunk, static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            });
        }

        return $events;
    }

    #[Test]
    #[TestDox('output_text.delta を本文増分（delta）イベントへ写す')]
    public function mapsTextDeltas(): void
    {
        $events = $this->parse(
            'data: {"type":"response.output_text.delta","delta":"Hello"}' . "\n\n" .
            'data: {"type":"response.output_text.delta","delta":" world"}' . "\n\n"
        );

        self::assertCount(2, $events);
        self::assertSame(StreamEvent::TYPE_DELTA, $events[0]->type);
        self::assertSame('Hello', $events[0]->text);
        self::assertSame(' world', $events[1]->text);
    }

    #[Test]
    #[TestDox('response.completed を継続トークン付きの completed イベントへ写す')]
    public function mapsCompletedWithContinuationToken(): void
    {
        $events = $this->parse('data: {"type":"response.completed","response":{"id":"resp_42"}}' . "\n\n");

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_COMPLETED, $events[0]->type);
        self::assertSame('resp_42', $events[0]->continuationToken);
    }

    #[Test]
    #[TestDox('error は code に応じた利用者向けメッセージの error イベントへ写す')]
    public function mapsError(): void
    {
        $events = $this->parse(
            'data: {"type":"error","code":"insufficient_quota","message":"You exceeded your current quota"}' . "\n\n"
        );

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_ERROR, $events[0]->type);
        self::assertStringContainsString('利用枠', (string) $events[0]->message);
    }

    #[Test]
    #[TestDox('SSE 行の途中でチャンクが分割されても復元して解析する')]
    public function reassemblesSplitChunks(): void
    {
        $events = $this->parse(
            'data: {"type":"response.output_text.',
            'delta","delta":"Hi"}' . "\n\n"
        );

        self::assertCount(1, $events);
        self::assertSame('Hi', $events[0]->text);
    }

    #[Test]
    #[TestDox('data 以外の行（event: / コメント / [DONE]）は無視する')]
    public function ignoresNonDataLines(): void
    {
        $events = $this->parse(
            'event: response.output_text.delta' . "\n" .
            ': keep-alive comment' . "\n" .
            'data: [DONE]' . "\n\n" .
            'data: {"type":"response.output_text.delta","delta":"X"}' . "\n\n"
        );

        self::assertCount(1, $events);
        self::assertSame('X', $events[0]->text);
    }
}
