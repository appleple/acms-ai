<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI\Endpoints\StreamingResponsesClient;

/**
 * ACMS_POST_AI_Chat
 * Streaming chat endpoint. Outputs SSE directly to client.
 */
class Chat extends ACMS_POST
{
    use AIPostTrait;

    public function post(): mixed
    {
        $this->initAiConfig();

        if ($this->apiKey === '' || $this->model === '') {
            return $this->jsonResponse([
                'message' => 'APIキーまたはモデルの設定がありません。',
                'errorCode' => 500
            ]);
        }

        $input = $this->Post->get("input");
        $previousResponseId = $this->Post->get("previousResponseId");
        $silent = $this->Post->get("silent") === '1';

        if (!$input) {
            return $this->jsonResponse([
                'message' => '無効なリクエストです。',
                'errorCode' => 400
            ]);
        }

        $client = new StreamingResponsesClient($this->apiKey, $this->model);
        $client->createPayload();
        $silentInstruction = $silent
            ? "\n\n## SILENT MODE (highest priority)\n" .
              "This is an automated request. " .
              "You MUST output the result wrapped in <correction>...</correction>. " .
              "Never omit the tag regardless of how simple or ambiguous the request is.\n"
            : "";
        $client->setInstructions(
            "You are a helpful assistant. Respond in Japanese unless the user asks otherwise.\n" .
            "\n" .
            "## Text Processing Tasks\n" .
            "When the user requests text transformation or processing "
            . "(rewriting, proofreading, summarizing, translating, paraphrasing, simplifying, expanding, "
            . "lengthening, etc.), "
            . "**always output the processed result exactly as requested**.\n" .
            "If the target text is not explicitly specified, use the most recently handled text in the "
            . "conversation.\n" .
            "\n" .
            "## Rules for the <correction> Tag (Highest Priority)\n" .
            "Whenever you process or generate text, you **must** wrap the result in a <correction> tag. "
            . "There are no exceptions to this rule.\n" .
            "\n" .
            "Before the <correction> tag, place only a brief sentence describing what you did.\n" .
            "All processed or generated text must be written inside the <correction> tag. "
            . "Do not write the result outside the tag.\n" .
            "\n" .
            "The only case where the <correction> tag may be omitted:\n" .
            "- Pure responses that involve no text processing or generation whatsoever "
            . "(greetings, simple yes/no answers only)\n" .
            "\n" .
            "Always use the tag in the following cases:\n" .
            "- Any form of text processing: translation, proofreading, summarizing, rewriting, paraphrasing, etc.\n" .
            "- Generating or creating new text\n" .
            "- Any response that includes a processed result requested by the user\n" .
            "\n" .
            "## Output Example\n" .
            "Here is the simplified version.\n" .
            "<correction>\n" .
            "The simplified text\n" .
            "</correction>" .
            $silentInstruction
        );
        $client->addInput('user', [
            $client->createTextContent($input)
        ]);

        if ($previousResponseId !== '') {
            $client->setPreviousResponseId($previousResponseId);
        }

        // Stream output directly - must run before any other output
        if (ob_get_level() !== 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        try {
            $client->stream();
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 チャット応答の生成に失敗しました', Common::exceptionArray($e));
            echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
        }

        exit;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    private function jsonResponse(array $data): mixed
    {
        return Common::responseJson($data);
    }
}
