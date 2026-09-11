<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Conversation;

use Acms\Plugins\AI\Services\AI\AiRequestInputLimit;
use Acms\Plugins\AI\Services\AI\AiRequestInputTooLargeException;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Acms\Plugins\AI\Services\AI\Conversation\ConversationInputBudget;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(ConversationInputBudget::class)]
final class ConversationInputBudgetTest extends TestCase
{
    #[Test]
    #[TestDox('今回入力を保持し、日本語をバイト数で数えて古い履歴から切り捨てる')]
    public function trimsOldestHistoryOnUtf8Boundary(): void
    {
        $request = new GenerationRequest(
            'model',
            [Message::user(ContentPart::text('問'))],
            '指',
        );
        $history = [
            Message::user(ContentPart::text('古')),
            Message::assistant(ContentPart::text('新')),
        ];

        $messages = (new ConversationInputBudget(new AiRequestInputLimit(9)))->fit($request, $history);

        self::assertCount(2, $messages);
        self::assertSame('新', $messages[0]->parts[0]->value);
        self::assertSame('問', $messages[1]->parts[0]->value);
    }

    #[Test]
    #[TestDox('24件の大きな履歴でも返す全メッセージは入力上限以内になる')]
    public function keepsLargeHistoryWithinBudget(): void
    {
        $limit = new AiRequestInputLimit(64);
        $request = new GenerationRequest('model', [Message::user(ContentPart::text(str_repeat('c', 16)))], 'system');
        $history = [];
        for ($index = 0; $index < 24; $index++) {
            $history[] = Message::user(ContentPart::text(str_repeat((string) ($index % 10), 16)));
        }

        $messages = (new ConversationInputBudget($limit))->fit($request, $history);

        self::assertTrue($limit->acceptsRequest($request, $messages, false));
        self::assertSame(str_repeat('2', 16), $messages[0]->parts[0]->value);
        self::assertSame(str_repeat('3', 16), $messages[1]->parts[0]->value);
        self::assertSame(str_repeat('c', 16), $messages[2]->parts[0]->value);
    }

    #[Test]
    #[TestDox('今回入力だけで上限を超える場合は外部送信せず拒否する')]
    public function rejectsCurrentRequestOverBudget(): void
    {
        $request = new GenerationRequest('model', [Message::user(ContentPart::text('日本語'))]);

        $this->expectException(AiRequestInputTooLargeException::class);
        (new ConversationInputBudget(new AiRequestInputLimit(8)))->fit($request, []);
    }
}
