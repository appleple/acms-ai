<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Credentials;

trait EndpointTrait
{
    private readonly Credentials $credentials;

    protected string $model = '';

    protected string $endpoint = '';

    /** @var list<array{role: string, content: array<int, array<string, mixed>>}> */
    protected array $input = [];

    /** @var string|null */
    protected ?string $instructions = null;

    /** @var string|null */
    protected ?string $previousResponseId = null;

    public function __construct(Credentials $credentials, string $model)
    {
        $this->credentials = $credentials;
        $this->endpoint = 'https://api.openai.com/v1/responses';
        $this->model = $model;
    }

    protected function resetEndpointState(): void
    {
        $this->input = [];
        $this->instructions = null;
        $this->previousResponseId = null;
    }

    public function createPayload(): void
    {
        $this->resetEndpointState();
    }

    /**
     * @param list<array<string, mixed>> $contents
     */
    public function addInput(string $role, array $contents): void
    {
        $this->input[] = [
            "role" => $role,
            "content" => $contents
        ];
    }

    /**
     * @return array{type: string, text: string}
     */
    public function createTextContent(string $text, string $role = 'user'): array
    {
        $type = $role === 'assistant' ? 'output_text' : 'input_text';
        return [
            "type" => $type,
            "text" => $text
        ];
    }

    public function setInstructions(string $instructions): void
    {
        $this->instructions = $instructions;
    }

    public function setPreviousResponseId(string $id): void
    {
        $this->previousResponseId = $id;
    }

    /**
     * @return array<string>
     */
    protected function buildHeaders(): array
    {
        return OpenAiRequestHeaders::fromCredentials($this->credentials);
    }
}
