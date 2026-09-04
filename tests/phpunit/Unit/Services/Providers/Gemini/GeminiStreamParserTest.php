<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\Gemini;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiStreamParser;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Gemini streamGenerateContent（alt=sse）のワイヤ列（data 行・チャンク分割・finishReason 終端）を
 * 中立の {@see StreamEvent} 列へデコードする純粋パーサの振る舞いを固定する。
 */
final class GeminiStreamParserTest extends TestCase
{
    /**
     * @param list<string> $chunks
     * @return list<StreamEvent>
     */
    private function feedAll(array $chunks): array
    {
        $parser = new GeminiStreamParser();
        $events = [];
        foreach ($chunks as $chunk) {
            $parser->feed($chunk, static function (StreamEvent $event) use (&$events): void {
                $events[] = $event;
            });
        }

        return $events;
    }

    #[Test]
    #[TestDox('candidates の text パートを delta イベントへ変換する')]
    public function decodesTextParts(): void
    {
        $events = $this->feedAll([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"やあ\"}],\"role\":\"model\"}}]}\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_DELTA, $events[0]->type);
        self::assertSame('やあ', $events[0]->text);
    }

    #[Test]
    #[TestDox('finishReason 付きチャンクは本文を出したあと completed（トークン無し）を返す')]
    public function finishReasonEmitsCompletedAfterText(): void
    {
        $events = $this->feedAll([
            "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"最後\"}]},\"finishReason\":\"STOP\"}]}\n\n",
        ]);

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_COMPLETED],
            array_map(static fn(StreamEvent $e): string => $e->type, $events)
        );
        self::assertSame('最後', $events[0]->text);
        self::assertNull($events[1]->continuationToken);
    }

    #[Test]
    #[TestDox('チャンク内の error を日本語メッセージ付き error イベントへ変換する')]
    public function decodesErrorChunk(): void
    {
        $events = $this->feedAll([
            "data: {\"error\":{\"code\":429,\"message\":\"quota\",\"status\":\"RESOURCE_EXHAUSTED\"}}\n\n",
        ]);

        self::assertCount(1, $events);
        self::assertSame(StreamEvent::TYPE_ERROR, $events[0]->type);
        self::assertIsString($events[0]->message);
        self::assertStringContainsString('クォータ', $events[0]->message);
    }

    #[Test]
    #[TestDox('SSE 行がチャンク境界で分断されても正しく復元する')]
    public function reassemblesChunkedLines(): void
    {
        $events = $this->feedAll([
            "data: {\"candidates\":[{\"content\":{\"par",
            "ts\":[{\"text\":\"分割\"}]}}]}\n\ndata: {\"candidates\":[{\"content\":{\"parts\":[]},\"finish",
            "Reason\":\"STOP\"}]}\n\n",
        ]);

        self::assertSame(
            [StreamEvent::TYPE_DELTA, StreamEvent::TYPE_COMPLETED],
            array_map(static fn(StreamEvent $e): string => $e->type, $events)
        );
        self::assertSame('分割', $events[0]->text);
    }

    #[Test]
    #[TestDox('壊れた JSON・data: 以外の行・空 candidates は無視する')]
    public function ignoresGarbage(): void
    {
        $events = $this->feedAll([
            ": comment\n\nnot-a-sse-line\ndata: {broken json}\n\ndata: \n\ndata: {\"candidates\":[]}\n\n",
        ]);

        self::assertSame([], $events);
    }
}
