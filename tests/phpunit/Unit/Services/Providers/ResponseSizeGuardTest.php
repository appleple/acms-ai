<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;
use Acms\Plugins\AI\Services\AI\Providers\BoundedResponseBuffer;
use Acms\Plugins\AI\Services\AI\Providers\BoundedSseStream;
use Acms\Plugins\AI\Services\AI\Providers\ResponseSizeException;
use Acms\Plugins\AI\Services\AI\Providers\ResponseSizeLimits;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(BoundedResponseBuffer::class)]
#[CoversClass(BoundedSseStream::class)]
#[CoversClass(ResponseSizeLimits::class)]
final class ResponseSizeGuardTest extends TestCase
{
    #[Test]
    #[TestDox('HTTP本文は日本語のバイト境界までは破損させず保持する')]
    public function responseBufferPreservesMultibyteBoundary(): void
    {
        $buffer = new BoundedResponseBuffer(6);

        self::assertSame(3, $buffer->append('あ'));
        self::assertSame(3, $buffer->append('い'));
        self::assertSame('あい', $buffer->body());

        $this->expectException(ResponseSizeException::class);
        $buffer->append('a');
    }

    #[Test]
    #[TestDox('改行のないSSE単一行が上限を超えた時点で拒否する')]
    public function rejectsOversizedIncompleteSseLine(): void
    {
        $stream = new BoundedSseStream(new ResponseSizeLimits(100, 10, 100, 10));
        $stream->push('data: 1234');

        $this->expectException(ResponseSizeException::class);
        $stream->push('5');
    }

    #[Test]
    #[TestDox('SSE受信全体が上限を超えた時点で拒否する')]
    public function rejectsOversizedSseResponse(): void
    {
        $stream = new BoundedSseStream(new ResponseSizeLimits(5, 10, 100, 10));
        $stream->push('123');

        $this->expectException(ResponseSizeException::class);
        $stream->push('456');
    }

    #[Test]
    #[TestDox('delta本文は日本語のバイト境界を守り、累積上限を超えたイベントを渡さない')]
    public function rejectsGeneratedTextOverMultibyteBoundary(): void
    {
        $stream = new BoundedSseStream(new ResponseSizeLimits(100, 100, 6, 10));
        $stream->assertEvent(StreamEvent::delta('あ'));
        $stream->assertEvent(StreamEvent::delta('い'));

        $this->expectException(ResponseSizeException::class);
        $stream->assertEvent(StreamEvent::delta('う'));
    }

    #[Test]
    #[TestDox('非ストリーム生成本文も日本語のバイト境界を超える前に拒否する')]
    public function rejectsNonStreamingGeneratedTextOverBoundary(): void
    {
        ResponseSizeLimits::assertGeneratedText('あい', 6);

        $this->expectException(ResponseSizeException::class);
        ResponseSizeLimits::assertGeneratedText('あいう', 6);
    }

    #[Test]
    #[TestDox('イベント数が上限を超えた時点で拒否する')]
    public function rejectsExcessiveEventCount(): void
    {
        $stream = new BoundedSseStream(new ResponseSizeLimits(100, 100, 100, 2));
        $stream->assertEvent(StreamEvent::completed(null));
        $stream->assertEvent(StreamEvent::completed(null));

        $this->expectException(ResponseSizeException::class);
        $stream->assertEvent(StreamEvent::completed(null));
    }
}
