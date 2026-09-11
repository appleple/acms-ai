<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\CmsVersionRequirement;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class CmsVersionRequirementTest extends TestCase
{
    #[Test]
    #[TestDox('3.2.28以前を拒否し、監査ログ修正版の3.2.29以降を許可する')]
    public function requiresAuditLogFixVersion(): void
    {
        self::assertFalse(CmsVersionRequirement::isSatisfied(null));
        self::assertFalse(CmsVersionRequirement::isSatisfied(''));
        self::assertFalse(CmsVersionRequirement::isSatisfied('3.2.28'));
        self::assertFalse(CmsVersionRequirement::isSatisfied('3.2.29-beta'));
        self::assertTrue(CmsVersionRequirement::isSatisfied('3.2.29'));
        self::assertTrue(CmsVersionRequirement::isSatisfied('3.2.32'));
        self::assertTrue(CmsVersionRequirement::isSatisfied('3.3.0'));
    }

    #[Test]
    #[TestDox('実行中CMSのVERSION定数も同じ最低要件で判定する')]
    public function checksCurrentCmsVersion(): void
    {
        self::assertSame(
            version_compare((string) constant('VERSION'), CmsVersionRequirement::MIN_VERSION, '>='),
            CmsVersionRequirement::currentIsSatisfied()
        );
    }
}
