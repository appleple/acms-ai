<?php

namespace Acms\Plugins\AI\Services\AI;

trait EndpointTrait
{
    /** @var string */
    protected $apiKey = '';

    /** @var string */
    protected $model = '';

    /** @var string */
    protected $endpoint = '';

    /** @var list<array{role: string, content: array<int, array<string, mixed>>}> */
    protected $input = [];

    /** @var string|null */
    protected $instructions = null;

    /** @var string|null */
    protected $previousResponseId = null;

    /**
     * @param string $apiKey
     * @param string $model
     * @return void
     */
    public function __construct(string $apiKey, string $model)
    {
        $this->apiKey = $apiKey;
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
        return [
            "Content-Type: application/json",
            "Authorization: Bearer {$this->apiKey}"
        ];
    }
}
