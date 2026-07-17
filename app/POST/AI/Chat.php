<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;

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

        if ($this->provider === null || $this->apiKey === '' || $this->model === '') {
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

        $request = new GenerationRequest(
            $this->model,
            [Message::user(ContentPart::text($input))],
            $this->buildInstructions($silent),
            null,
            null,
            $previousResponseId !== '' ? $previousResponseId : null
        );

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
            // 各 SSE チャンクをそのままクライアントへ echo/flush する（正準ワイヤ形式）。
            // HTTP 出力の責務はここが持ち、プロバイダ実装はワイヤ列の生成に専念する。
            $this->provider->streamText($request, static function (string $chunk): void {
                echo $chunk;
                if (ob_get_level() !== 0) {
                    ob_flush();
                }
                flush();
            });
        } catch (\Exception $e) {
            Logger::error('【AI plugin】 チャット応答の生成に失敗しました', Common::exceptionArray($e));
            echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
        }

        exit;
    }

    /**
     * チャットの system 指示を組み立てる。silent モードでは <correction> タグ強制を最優先で付加する。
     */
    private function buildInstructions(bool $silent): string
    {
        $silentInstruction = $silent
            ? "\n\n## SILENT MODE (highest priority)\n" .
              "This is an automated request. " .
              "You MUST output the result wrapped in <correction>...</correction>. " .
              "Never omit the tag regardless of how simple or ambiguous the request is.\n"
            : "";

        return "You are a helpful assistant. Respond in Japanese unless the user asks otherwise.\n" .
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
            $silentInstruction;
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
