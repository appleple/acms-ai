<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;

final class MediaFieldFakeProvider implements AiProvider
{
    public function __construct(private readonly string|false $text)
    {
    }

    public function id(): string
    {
        return 'fake';
    }

    public function supports(Capability $capability): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function generateText(GenerationRequest $request): GenerationResult
    {
        return new GenerationResult(is_string($this->text) ? $this->text : null);
    }

    public function streamText(GenerationRequest $request, callable $onEvent): void
    {
    }
}
