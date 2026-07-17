<?php

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\StreamEvent;

/**
 * Streaming version of ResponsesClient.
 * OpenAI Responses API の SSE ストリームを中立の {@see StreamEvent} へデコードして呼び出し側へ渡す。
 */
class StreamingResponsesClient
{
    use EndpointTrait;

    /**
     * ストリーミング要求を実行し、OpenAI SSE を中立の {@see StreamEvent} へデコードして $onEvent へ渡す。
     * HTTP 出力（echo/flush）や SSE 整形は呼び出し側の責務なので、ワイヤ層は配信方法に依存しない。
     * setTextFormat は使わない（チャットは自由文を返す）。
     *
     * @param callable(StreamEvent): void $onEvent
     * @return void
     * @codeCoverageIgnore SSE を curl コールバックで逐次受信する実通信の I/O 境界。デコード自体は
     *   {@see ResponsesStreamParser} でユニット検証するため、ここは実機/E2E で担保する。
     */
    public function stream(callable $onEvent): void
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
        $parser = new ResponsesStreamParser();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($parser, $onEvent): int {
                $parser->feed($data, $onEvent);
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
