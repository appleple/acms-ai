<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\POST\AI;

use Acms\Plugins\AI\POST\AI\Config;
use Acms\TestingFramework\TestCase;
use Field_Validation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class ConfigTest extends TestCase
{
    #[Test]
    #[TestDox('要件未満のCMSでは資格情報補完やコアの設定保存へ進まない')]
    public function rejectsSaveOnAffectedCmsVersion(): void
    {
        $handler = new class () extends Config {
            public string $capturedError = '';

            protected function isCmsVersionSupported(): bool
            {
                return false;
            }

            protected function protectAuditLogRequest(): void
            {
                // AuditLogSanitizer自体は専用テストで検証する。
            }

            protected function addError($message): void
            {
                $this->capturedError = $message;
            }
        };
        $post = new Field_Validation();
        $post->set('ai_api_key', 'must-not-be-saved');
        $handler->Post = $post;

        $result = $handler->post();

        self::assertSame($post, $result);
        self::assertStringContainsString('3.2.29', $handler->capturedError);
        self::assertFalse($post->isExists('notice_mess'));
    }
}
