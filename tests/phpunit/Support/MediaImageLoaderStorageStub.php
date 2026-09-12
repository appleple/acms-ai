<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

final class MediaImageLoaderStorageStub
{
    public int $getCalls = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    public function isSafeRelativePath(string $path): bool
    {
        return true;
    }

    public function exists(string $path): bool
    {
        return true;
    }

    public function isFile(string $path): bool
    {
        return true;
    }

    public function getFileSize(string $path): int
    {
        return strlen($this->bytes);
    }

    public function getMimeType(string $path): string
    {
        return 'image/png';
    }

    public function get(string $path, string $baseDir): string
    {
        $this->getCalls++;
        return $this->bytes;
    }
}
