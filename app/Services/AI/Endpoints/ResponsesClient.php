<?php

namespace Acms\Plugins\AI\Services\AI\Endpoints;

use Acms\Plugins\AI\Services\AI\EndpointTrait;

class ResponsesClient
{
    use EndpointTrait;

    /** @var array|null */
    private $textFormat = null;

    public function createPayload(): void
    {
        $this->resetEndpointState();
        $this->textFormat = null;
    }

    public function createImageContent(string $url): array
    {
        return [
            "type" => "input_image",
            "image_url" => $url
        ];
    }

    public function createOutputTextContent(string $text): array
    {
        return [
            "type" => "output_text",
            "text" => $text
        ];
    }

    /**
     * @param array $format e.g. ['type' => 'json_schema', 'name' => 'tag_list', 'schema' => [...]]
     */
    public function setTextFormat(array $format): void
    {
        $this->textFormat = $format;
    }

    /**
     * @param string $json
     * @param array<string> $headers
     * @return string|false
     */
    public function exec(string $json, array $headers): string|false
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
        ]);
        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new \Exception("cURL Error: " . $error);
        }
        return $result;
    }

    public function request(): mixed
    {
        $postData = [
            "model" => $this->model,
            "input" => $this->input,
            "store" => true
        ];

        if ($this->instructions !== null) {
            $postData['instructions'] = $this->instructions;
        }

        if ($this->previousResponseId !== null) {
            $postData['previous_response_id'] = $this->previousResponseId;
        }

        if ($this->textFormat !== null) {
            $postData['text'] = ['format' => $this->textFormat];
        }

        $json = json_encode($postData);

        try {
            $result = $this->exec($json, $this->buildHeaders());
            $parse = json_decode($result);
            return $parse;
        } catch (\Exception $e) {
            \AcmsLogger::error($e->getMessage());
            return null;
        }
    }

    /**
     * Extract text content from Responses API output
     * Response structure: { output: [{ type: "message", content: [{ type: "output_text", text: "..." }] }] }
     *
     * @param object $response
     * @return string|null
     */
    public static function extractText($response): ?string
    {
        if (!isset($response->output)) {
            return null;
        }
        foreach ($response->output as $output) {
            if ($output->type === 'message' && isset($output->content)) {
                foreach ($output->content as $content) {
                    if ($content->type === 'output_text' && isset($content->text)) {
                        return $content->text;
                    }
                }
            }
        }
        return null;
    }
}
