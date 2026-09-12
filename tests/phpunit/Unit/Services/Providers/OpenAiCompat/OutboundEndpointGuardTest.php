<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Providers\OpenAiCompat;

use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OutboundEndpointGuard;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(OutboundEndpointGuard::class)]
final class OutboundEndpointGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedAddresses(): iterable
    {
        foreach (
            [
                '127.0.0.1',
                '10.0.0.1',
                '169.254.169.254',
                '100.64.0.1',
                '198.18.0.1',
                '224.0.0.1',
                '::1',
                '::ffff:127.0.0.1',
                'fc00::1',
                'fe80::1',
                'ff00::1',
                '2001:db8::1',
            ] as $address
        ) {
            yield $address => [$address];
        }
    }

    #[Test]
    #[DataProvider('blockedAddresses')]
    #[TestDox('非公開・特殊用途IP $address を拒否する')]
    public function rejectsNonPublicAddresses(string $address): void
    {
        $guard = new OutboundEndpointGuard(static fn (): array => [$address]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('非公開IPアドレス');

        $guard->resolveForCurl('https://example.com/v1/chat/completions');
    }

    #[Test]
    #[TestDox('公開IPだけを返すDNS名は検査済みIPへ接続先を固定する')]
    public function pinsResolvedPublicAddress(): void
    {
        $guard = new OutboundEndpointGuard(
            static fn (string $host): array => $host === 'api.example.com'
                ? ['93.184.216.34', '2606:4700:4700::1111']
                : []
        );

        self::assertSame(
            ['api.example.com:8443:93.184.216.34'],
            $guard->resolveForCurl('https://api.example.com:8443/v1/chat/completions'),
        );
    }

    #[Test]
    #[TestDox('公開IPと非公開IPが混在するDNS応答は全体を拒否する')]
    public function rejectsMixedDnsAnswers(): void
    {
        $guard = new OutboundEndpointGuard(static fn (): array => ['93.184.216.34', '127.0.0.1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('非公開IPアドレス');

        $guard->resolveForCurl('https://example.com/v1');
    }

    #[Test]
    #[TestDox('公開IPリテラルはDNS固定不要として許可する')]
    public function acceptsPublicIpLiteral(): void
    {
        $guard = new OutboundEndpointGuard(static fn (): array => self::fail('DNS lookup should not run'));

        self::assertSame([], $guard->resolveForCurl('https://93.184.216.34/v1'));
        self::assertSame([], $guard->resolveForCurl('https://[2606:4700:4700::1111]/v1'));
    }

    #[Test]
    #[TestDox('名前解決結果が空なら安全側に拒否する')]
    public function rejectsEmptyDnsAnswer(): void
    {
        $guard = new OutboundEndpointGuard(static fn (): array => []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('名前解決に失敗');

        $guard->resolveForCurl('https://missing.example/v1');
    }
}
