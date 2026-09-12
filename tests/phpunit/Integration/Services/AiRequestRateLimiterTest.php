<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Integration\Services;

use Acms\Plugins\AI\Services\AI\AiRequestRateLimiter;
use Acms\TestingFramework\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class AiRequestRateLimiterTest extends DatabaseTestCase
{
    private const TEST_BLOG_ID = 2147483645;
    private const TEST_USER_ID = 2147483644;
    private const PARALLEL_WORKERS = 8;

    #[Test]
    #[TestDox('ブログ・ユーザー単位の試行回数を超えると拒否する')]
    public function rejectsRequestsOverLimit(): void
    {
        $limiter = new AiRequestRateLimiter(1, 2, 5);

        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertFalse($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
    }

    #[Test]
    #[TestDox('異なるブログまたはユーザーの試行回数を共有しない')]
    public function isolatesCountersByBlogAndUser(): void
    {
        $limiter = new AiRequestRateLimiter(1, 1, 5);

        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertFalse($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID + 1));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID - 1, self::TEST_USER_ID));
    }

    #[Test]
    #[TestDox('上限が 0 なら試行を拒否しない')]
    public function allowsRequestsWhenDisabled(): void
    {
        $limiter = new AiRequestRateLimiter(1, 0, 5);

        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
        self::assertTrue($limiter->consume(self::TEST_BLOG_ID, self::TEST_USER_ID));
    }

    #[Test]
    #[TestDox('明示的な開始バリアから並列実行しても上限を超えて成功しない')]
    public function serializesConcurrentConsumption(): void
    {
        $directory = sys_get_temp_dir() . '/acms-ai-rate-limit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));

        $processes = [];
        try {
            for ($index = 0; $index < self::PARALLEL_WORKERS; $index++) {
                $processes[] = $this->startWorker($directory, $index);
            }

            $this->waitForReadyBarrier($directory);
            file_put_contents($directory . '/start', 'start');

            $allowed = 0;
            foreach ($processes as [$process, $pipes, $resultPath]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                self::assertSame(0, $exitCode, trim($stdout . "\n" . $stderr));
                $allowed += trim((string) file_get_contents($resultPath)) === '1' ? 1 : 0;
            }

            self::assertSame(1, $allowed);
        } finally {
            if (!is_file($directory . '/start')) {
                file_put_contents($directory . '/start', 'start');
            }
            $this->cleanupRateLimitRows();
            $paths = glob($directory . '/*');
            if ($paths !== false) {
                foreach ($paths as $path) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * @return array{resource, array{1: resource, 2: resource}, string}
     */
    private function startWorker(string $directory, int $index): array
    {
        $readyPath = "{$directory}/ready-{$index}";
        $resultPath = "{$directory}/result-{$index}";
        $worker = __DIR__ . '/../../Support/rate-limit-worker.php';
        $process = proc_open(
            [
                PHP_BINARY,
                $worker,
                'consume',
                (string) self::TEST_BLOG_ID,
                (string) self::TEST_USER_ID,
                $readyPath,
                "{$directory}/start",
                $resultPath,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return [$process, [1 => $pipes[1], 2 => $pipes[2]], $resultPath];
    }

    private function waitForReadyBarrier(string $directory): void
    {
        $deadline = microtime(true) + 10;
        do {
            $ready = glob($directory . '/ready-*');
            if ($ready !== false && count($ready) === self::PARALLEL_WORKERS) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Parallel workers did not reach the start barrier.');
    }

    private function cleanupRateLimitRows(): void
    {
        $worker = __DIR__ . '/../../Support/rate-limit-worker.php';
        $command = [
            PHP_BINARY,
            $worker,
            'cleanup',
            (string) self::TEST_BLOG_ID,
            (string) self::TEST_USER_ID,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}
