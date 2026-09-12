<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

final class MediaImageLoaderMediaStub
{
    /** @var list<int> */
    public array $getMediaIds = [];

    /** @var list<int> */
    public array $validatedBlogIds = [];

    /** @var list<int> */
    public array $editedMediaIds = [];

    /** @param array<string, mixed> $media */
    public function __construct(
        private readonly array $media,
        private readonly bool $valid = true,
        private readonly bool $editable = true,
    ) {
    }

    /** @return array<string, mixed> */
    public function getMedia(int $mediaId): array
    {
        $this->getMediaIds[] = $mediaId;
        return $this->media;
    }

    public function validate(int $blogId): bool
    {
        $this->validatedBlogIds[] = $blogId;
        return $this->valid;
    }

    public function canEdit(int $mediaId): bool
    {
        $this->editedMediaIds[] = $mediaId;
        return $this->editable;
    }
}
