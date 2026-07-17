<?php

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;

class ResponsesClient
{
    use EndpointTrait;

    /** @var array<string, mixed>|null */
    private $textFormat = null;

    public function createPayload(): void
    {
        $this->resetEndpointState();
        $this->textFormat = null;
    }

    /**
     * @return array{type: string, image_url: string}
     */
    public function createImageContent(string $url): array
    {
        return [
            "type" => "input_image",
            "image_url" => $url
        ];
    }

    /**
     * @return array{type: string, text: string}
     */
    public function createOutputTextContent(string $text): array
    {
        return [
            "type" => "output_text",
            "text" => $text
        ];
    }

    /**
     * @param array<string, mixed> $format e.g. ['type' => 'json_schema', 'name' => 'tag_list', 'schema' => [...]]
     */
    public function setTextFormat(array $format): void
    {
        $this->textFormat = $format;
    }

    /**
     * @param string $json
     * @param list<string> $headers
     * @return string|false
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
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

        if (curl_errno($ch) !== 0) {
            $error = curl_error($ch);
            throw new \Exception("cURL Error: " . $error);
        }
        return is_string($result) ? $result : false;
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
        if ($json === false) {
            Logger::error('【AI plugin】 JSON encode error: ' . json_last_error_msg());
            return null;
        }

        try {
            $result = $this->exec($json, $this->buildHeaders());
            if ($result === false) {
                return null;
            }
            $parse = json_decode($result);
            return $parse;
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 OpenAI API リクエストに失敗しました', Common::exceptionArray($e));
            return null;
        }
    }

    /**
     * Extract text content from Responses API output
     * Response structure: { output: [{ type: "message", content: [{ type: "output_text", text: "..." }] }] }
     *
     * @param mixed $response json_decode 済みの Responses API 応答（想定は \stdClass）
     * @return string|null
     */
    public static function extractText($response): ?string
    {
        if (!$response instanceof \stdClass || !isset($response->output) || !is_iterable($response->output)) {
            return null;
        }
        foreach ($response->output as $output) {
            if (!$output instanceof \stdClass || ($output->type ?? null) !== 'message') {
                continue;
            }
            if (!isset($output->content) || !is_iterable($output->content)) {
                continue;
            }
            foreach ($output->content as $content) {
                if (
                    $content instanceof \stdClass
                    && ($content->type ?? null) === 'output_text'
                    && isset($content->text)
                    && is_string($content->text)
                ) {
                    return $content->text;
                }
            }
        }
        return null;
    }
}
