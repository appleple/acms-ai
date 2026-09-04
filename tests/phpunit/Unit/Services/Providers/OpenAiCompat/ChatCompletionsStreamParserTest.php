<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\ChatCompletionsStreamParser;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Chat Completions（stream=true）のワイヤ列（data 行・[DONE] 終端・チャンク分割）を
 * 中立の {@see StreamEvent} 列へデコードする純粋パーサの振る舞いを固定する。
 */
final class ChatCompletionsStreamParserTest extends TestCase
{
    /**
     * @param list<string> $chunks
     * @return list<StreamEvent>
     */
    private function feedAll(array $chunks): array
    {
        $parser = new ChatCompletionsStreamParser();
        $events = [];
        foreach ($chunks as $chunk) {
            $parser->feed($chunk, static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            });
        }

        return $events;
    }

    #[Test]
    #[TestDox('choices[].delta.content を delta イベントへ変換する')]
    public function decodesDeltaContent(): void
    {
        $events = $this->feedAll([
            "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"やあ\"}}]}\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_DELTA, $events[0]->type);
        self::assertSame('やあ', $events[0]->text);
    }

    #[Test]
    #[TestDox('[DONE] を completed（トークン無し）へ変換し、重複しても 1 回だけ通知する')]
    public function decodesDoneOnce(): void
    {
        $events = $this->feedAll([
            "data: [DONE]\n\ndata: [DONE]\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_COMPLETED, $events[0]->type);
        self::assertNull($events[0]->continuationToken);
    }

    #[Test]
    #[TestDox('チャンク内の error を日本語メッセージ付き error イベントへ変換する')]
    public function decodesErrorChunk(): void
    {
        $events = $this->feedAll([
            "data: {\"error\":{\"message\":\"rate limited\",\"type\":\"rate_limit_error\"}}\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_ERROR, $events[0]->type);
        self::assertIsString($events[0]->message);
        self::assertStringContainsString('集中', $events[0]->message);
    }

    #[Test]
    #[TestDox('SSE 行がチャンク境界で分断されても正しく復元する')]
    public function reassemblesChunkedLines(): void
    {
        $events = $this->feedAll([
            "data: {\"choices\":[{\"delta\":{\"con",
            "tent\":\"分割\"}}]}\n\ndata: [DO",
            "NE]\n\n",
        ]);

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_COMPLETED],
            array_map(static fn(StreamEvent $e): string => $e->type, $events)
        );
        self::assertSame('分割', $events[0]->text);
    }

    #[Test]
    #[TestDox('content の無い delta（role のみ・finish_reason チャンク）・壊れた行は無視する')]
    public function ignoresNonContentChunksAndGarbage(): void
    {
        $events = $this->feedAll([
            "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n" .
            "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n" .
            ": comment\n\nnot-a-sse-line\ndata: {broken json}\n\ndata: \n\n",
        ]);

        self::assertSame([], $events);
    }
}
