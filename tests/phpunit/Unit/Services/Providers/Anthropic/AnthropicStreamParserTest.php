<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\Anthropic;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicStreamParser;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Anthropic SSE のワイヤ列（event/data 行・チャンク分割）を中立の {@see StreamEvent} 列へ
 * デコードする純粋パーサの振る舞いを固定する。
 */
final class AnthropicStreamParserTest extends TestCase
{
    /**
     * @param list<string> $chunks
     * @return list<StreamEvent>
     */
    private function feedAll(array $chunks): array
    {
        $parser = new AnthropicStreamParser();
        $events = [];
        foreach ($chunks as $chunk) {
            $parser->feed($chunk, static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            });
        }

        return $events;
    }

    #[Test]
    #[TestDox('content_block_delta の text_delta を delta イベントへ変換する')]
    public function decodesTextDelta(): void
    {
        $events = $this->feedAll([
            "event: content_block_delta\n" .
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"やあ\"}}\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_DELTA, $events[0]->type);
        self::assertSame('やあ', $events[0]->text);
    }

    #[Test]
    #[TestDox('message_stop を completed（トークン無し）へ変換する')]
    public function decodesMessageStop(): void
    {
        $events = $this->feedAll(["data: {\"type\":\"message_stop\"}\n\n"]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_COMPLETED, $events[0]->type);
        self::assertNull($events[0]->continuationToken);
    }

    #[Test]
    #[TestDox('error イベントを日本語メッセージ付き error へ変換する')]
    public function decodesErrorEvent(): void
    {
        $events = $this->feedAll([
            "data: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_ERROR, $events[0]->type);
        self::assertIsString($events[0]->message);
        self::assertStringContainsString('混雑', $events[0]->message);
    }

    #[Test]
    #[TestDox('SSE 行がチャンク境界で分断されても正しく復元する')]
    public function reassemblesChunkedLines(): void
    {
        $events = $this->feedAll([
            "data: {\"type\":\"content_block_delta\",\"del",
            "ta\":{\"type\":\"text_delta\",\"text\":\"分割\"}}\n\ndata: {\"type\":\"mess",
            "age_stop\"}\n\n",
        ]);

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_COMPLETED],
            array_map(static fn(StreamEvent $e): string => $e->type, $events)
        );
        self::assertSame('分割', $events[0]->text);
    }

    #[Test]
    #[TestDox('ping・message_start などの管理イベントと event: 行は無視する')]
    public function ignoresNonContentEvents(): void
    {
        $events = $this->feedAll([
            "event: message_start\n" .
            "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_1\"}}\n\n" .
            "event: ping\n" .
            "data: {\"type\":\"ping\"}\n\n" .
            "event: content_block_start\n" .
            "data: {\"type\":\"content_block_start\",\"index\":0}\n\n" .
            "event: message_delta\n" .
            "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"}}\n\n",
        ]);

        self::assertSame([], $events);
    }

    #[Test]
    #[TestDox('text_delta 以外の delta（ツール入力など）は無視する')]
    public function ignoresNonTextDeltas(): void
    {
        $events = $this->feedAll([
            "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{\"}}\n\n",
        ]);

        self::assertSame([], $events);
    }

    #[Test]
    #[TestDox('壊れた JSON や data: 以外の行は無視する')]
    public function ignoresGarbage(): void
    {
        $events = $this->feedAll([
            ": comment\n\nnot-a-sse-line\ndata: {broken json}\n\ndata: \n\n",
        ]);

        self::assertSame([], $events);
    }
}
