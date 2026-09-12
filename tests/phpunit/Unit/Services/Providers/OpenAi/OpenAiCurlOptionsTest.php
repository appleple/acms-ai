<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiCurlOptions;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(OpenAiCurlOptions::class)]
final class OpenAiCurlOptionsTest extends TestCase
{
    #[Test]
    #[TestDox('モデル一覧は接続10秒・全体30秒で終了する')]
    public function modelListHasFiniteTimeouts(): void
    {
        $options = OpenAiCurlOptions::modelList();

        self::assertSame(10, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(30, $options[CURLOPT_TIMEOUT]);
    }

    #[Test]
    #[TestDox('非ストリーム生成は接続10秒・全体180秒で終了する')]
    public function requestHasFiniteTimeouts(): void
    {
        $options = OpenAiCurlOptions::request();

        self::assertSame(10, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(180, $options[CURLOPT_TIMEOUT]);
    }

    #[Test]
    #[TestDox('ストリームは停止検出と10分の上限を持ち、通常生成には広い猶予を与える')]
    public function streamHasLowSpeedAndOverallTimeouts(): void
    {
        $options = OpenAiCurlOptions::stream();

        self::assertSame(10, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(600, $options[CURLOPT_TIMEOUT]);
        self::assertSame(1, $options[CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(120, $options[CURLOPT_LOW_SPEED_TIME]);
        self::assertGreaterThan(OpenAiCurlOptions::request()[CURLOPT_TIMEOUT], $options[CURLOPT_TIMEOUT]);
    }
}
