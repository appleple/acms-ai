<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat;

/**
 * OpenAI互換APIの接続先を公開HTTPSアドレスに限定し、検査済みIPへcURLを固定する。
 */
final class OutboundEndpointGuard
{
    /** @var list<string> */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolveHost;

    /** @param (\Closure(string): list<string>)|null $resolveHost */
    public function __construct(?\Closure $resolveHost = null)
    {
        $this->resolveHost = $resolveHost ?? self::resolveHost(...);
    }

    /**
     * DNSで得た全アドレスを検査し、接続に使う1件をCURLOPT_RESOLVE形式で返す。
     * IPリテラルはURL自体が接続先を固定するため、検査後に空配列を返す。
     *
     * @return list<string>
     */
    public function resolveForCurl(string $url): array
    {
        $parts = parse_url($url);
        if (
            $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || $parts['host'] === ''
        ) {
            throw new \RuntimeException('OpenAI互換エンドポイントは有効なHTTPS URLを指定してください。');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($host);
            return [];
        }

        $addresses = array_values(array_unique(($this->resolveHost)($host)));
        if ($addresses === []) {
            throw new \RuntimeException('OpenAI互換エンドポイントの名前解決に失敗しました。');
        }
        foreach ($addresses as $address) {
            $this->assertPublicIp($address);
        }

        $address = $addresses[0];
        if (str_contains($address, ':')) {
            $address = '[' . $address . ']';
        }
        $port = $parts['port'] ?? 443;

        return [sprintf('%s:%d:%s', $host, $port, $address)];
    }

    private function assertPublicIp(string $address): void
    {
        if (
            filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false
        ) {
            throw new \RuntimeException('OpenAI互換エンドポイントに非公開IPアドレスは指定できません。');
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->matchesCidr($address, $cidr)) {
                throw new \RuntimeException('OpenAI互換エンドポイントに非公開IPアドレスは指定できません。');
            }
        }
    }

    private function matchesCidr(string $address, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $packedAddress = @inet_pton($address);
        $packedNetwork = @inet_pton($network);
        if ($packedAddress === false || $packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $fullBytes = intdiv($prefix, 8);
        if (substr($packedAddress, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($packedAddress[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
