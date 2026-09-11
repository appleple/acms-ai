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

        while ($history !== []) {
            $messages = [...$history, ...$request->messages];
            if ($this->limit->acceptsRequest($request, $messages, false)) {
                return $messages;
            }
            array_shift($history);
        }

        return $request->messages;
    }
}
