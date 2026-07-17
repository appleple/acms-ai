<?php

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

/**
 * Streaming version of ResponsesClient.
 * Proxies the OpenAI Responses API SSE stream chunk-by-chunk to a caller-supplied callback.
 */
class StreamingResponsesClient
{
    use EndpointTrait;

    /**
     * Execute a streaming request and hand each raw SSE chunk to $onChunk.
     * HTTP output concerns (echo/flush) are the caller's responsibility so the wire layer
     * stays agnostic to how the chunks are delivered. Does not use setTextFormat - chat
     * returns free-form text.
     *
     * @param callable(string): void $onChunk
     * @return void
     * @codeCoverageIgnore SSE を curl コールバックで逐次出力する実通信の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    public function stream(callable $onChunk): void
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
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($onChunk): int {
                $onChunk($data);
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
