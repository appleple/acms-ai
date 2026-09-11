<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Credentials;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiRequestHeaders;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class OpenAiRequestHeadersTest extends TestCase
{
    #[Test]
    #[TestDox('API キーが空白だけなら拒否する')]
    public function rejectsEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OpenAiRequestHeaders::fromCredentials(new Credentials('   '));
    }

    #[Test]
    #[TestDox('任意 ID の空白を除去し、空白だけの値は送信しない')]
    public function normalizesOptionalValues(): void
    {
        $headers = OpenAiRequestHeaders::fromCredentials(new Credentials('  key  ', [
            'organizationId' => '  org-test  ',
            'projectId' => '   ',
        ]));

        self::assertSame([
            'Content-Type: application/json',
            'Authorization: Bearer key',
            'OpenAI-Organization: org-test',
        ], $headers);
    }

    #[Test]
    #[TestDox('認証情報内の改行を拒否して追加ヘッダーへの混入を防ぐ')]
    public function rejectsLineBreaks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OpenAiRequestHeaders::fromCredentials(new Credentials('key', [
            'organizationId' => "org-test\r\nX-Injected: value",
        ]));
    }
}
