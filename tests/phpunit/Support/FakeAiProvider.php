<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Support;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;

/**
 * プロバイダ非依存の消費側ロジック（MediaFieldGenerator 等）を検証するための {@see AiProvider} ダブル。
 *
 * generateText は設定した {@see GenerationResult} を返し、受け取った {@see GenerationRequest} を
 * 記録する（リクエスト変換の検証用）。streamText は設定したイベント列を流す。
 */
final class FakeAiProvider implements AiProvider
{
    /** @var GenerationResult generateText が返す結果 */
    public GenerationResult $result;

    /** @var list<StreamEvent> streamText が流すイベント列 */
    public array $streamEvents = [];

    /** @var GenerationRequest|null 直近に受け取ったリクエスト */
    public ?GenerationRequest $lastRequest = null;

    /** @var list<Capability> supports() が true を返す機能 */
    public array $capabilities = [
        Capability::TextGeneration,
        Capability::StructuredOutput,
        Capability::VisionInput,
        Capability::Streaming,
    ];

    public bool $configured = true;

    public function __construct(?GenerationResult $result = null)
    {
        $this->result = $result ?? new GenerationResult('{}');
    }

    public function id(): string
    {
        return 'fake';
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function generateText(GenerationRequest $request): GenerationResult
    {
        $this->lastRequest = $request;

        return $this->result;
    }

    public function streamText(GenerationRequest $request, callable $onEvent): void
    {
        $this->lastRequest = $request;
        foreach ($this->streamEvents as $event) {
            $onEvent($event);
        }
    }
}
