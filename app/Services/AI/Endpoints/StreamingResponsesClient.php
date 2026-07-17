<?php

namespace Acms\Plugins\AI\Services\AI\Endpoints;

use Acms\Plugins\AI\Services\AI\EndpointTrait;

/**
 * Streaming version of ResponsesClient.
 * Proxies OpenAI Responses API SSE stream to the client.
 */
class StreamingResponsesClient
{
    use EndpointTrait;

    /**
     * Execute streaming request and output SSE to client.
     * Does not use setTextFormat - chat returns free-form text.
     *
     * @return void
     * @codeCoverageIgnore SSE を curl コールバックで逐次出力する実通信の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    public function stream(): void
    {
        $postData = [
            "model" => $this->model,
            "input" => $this->input,
            "stream" => true,
            "store" => true
        ];

        if ($this->instructions !== null) {
            $postData['instructions'] = $this->instructions;
        }

        if ($this->previousResponseId !== null) {
            $postData['previous_response_id'] = $this->previousResponseId;
        }

        $json = json_encode($postData);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data): int {
                echo $data;
                if (ob_get_level() !== 0) {
                    ob_flush();
                }
                flush();
                return strlen($data);
            },
        ]);

        curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            $error = curl_error($ch);
            throw new \Exception("cURL Error: " . $error);
        }
    }
}
