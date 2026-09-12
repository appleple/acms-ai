<?php

declare(strict_types=1);

use Acms\Plugins\AI\Services\AI\AiRequestRateLimiter;
use Acms\Services\Facades\Database as DB;

require_once __DIR__ . '/../bootstrap.php';

$mode = $argv[1] ?? '';
$blogId = isset($argv[2]) ? (int) $argv[2] : 0;
$userId = isset($argv[3]) ? (int) $argv[3] : 0;
$lockKey = "acms-ai:{$blogId}:{$userId}";

if ($mode === 'cleanup') {
    foreach (['lock_source', 'lock'] as $table) {
        $sql = SQL::newDelete($table);
        $sql->addWhereOpr($table . '_key', $lockKey);
        DB::query($sql->get(dsn()), 'exec');
    }
    exit(0);
}

if ($mode !== 'consume' || $blogId < 1 || $userId < 1) {
    fwrite(STDERR, "Invalid rate-limit worker arguments.\n");
    exit(2);
}

$readyPath = $argv[4] ?? '';
$startPath = $argv[5] ?? '';
$resultPath = $argv[6] ?? '';
if ($readyPath === '' || $startPath === '' || $resultPath === '') {
    fwrite(STDERR, "Missing rate-limit worker paths.\n");
    exit(2);
}

file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 10;
while (!is_file($startPath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the start barrier.\n");
        exit(3);
    }
    usleep(10_000);
}

$allowed = (new AiRequestRateLimiter(1, 1, 5))->consume($blogId, $userId);
file_put_contents($resultPath, $allowed ? '1' : '0');
