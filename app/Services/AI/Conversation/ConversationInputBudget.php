<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Conversation;

use Acms\Plugins\AI\Services\AI\AiRequestInputLimit;
use Acms\Plugins\AI\Services\AI\AiRequestInputTooLargeException;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;

/**
 * 今回の入力を必ず保持し、復元履歴を古い順に落として外向き入力を予算内へ収める。
 */
final class ConversationInputBudget
{
    public function __construct(private readonly AiRequestInputLimit $limit)
    {
    }

    /**
     * @param list<Message> $history
     * @return list<Message>
     */
    public function fit(GenerationRequest $request, array $history): array
    {
        if (!$this->limit->acceptsRequest($request, $request->messages, false)) {
            throw new AiRequestInputTooLargeException();
        }

        $history = $this->withoutLeadingAssistant($history);
        while ($history !== []) {
            $messages = [...$history, ...$request->messages];
            if ($this->limit->acceptsRequest($request, $messages, false)) {
                return $messages;
            }
            array_shift($history);
            $history = $this->withoutLeadingAssistant($history);
        }

        return $request->messages;
    }

    /**
     * user を削った後に assistant だけを残さず、会話ターンの境界まで進める。
     *
     * @param list<Message> $history
     * @return list<Message>
     */
    private function withoutLeadingAssistant(array $history): array
    {
        while ($history !== [] && $history[0]->role === Message::ROLE_ASSISTANT) {
            array_shift($history);
        }

        return $history;
    }
}
