<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

use Acms\Plugins\AI\Services\AI\Contracts\Credentials;

/**
 * OpenAI API 共通の認証・ルーティングヘッダーを組み立てる。
 *
 * API キーだけを必須とし、Organization ID / Project ID は設定された場合だけ送る。
 * /models と Responses API（通常・ストリーミング）で同じ規則を共有する。
 */
final class OpenAiRequestHeaders
{
    /**
     * @return list<string>
     */
    public static function fromCredentials(Credentials $credentials): array
    {
        $apiKey = self::normalize($credentials->apiKey());
        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenAI API key is required.');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        self::appendOptional($headers, 'OpenAI-Organization', $credentials->attribute('organizationId'));
        self::appendOptional($headers, 'OpenAI-Project', $credentials->attribute('projectId'));

        return $headers;
    }

    /**
     * @param list<string> $headers
     */
    private static function appendOptional(array &$headers, string $name, string $value): void
    {
        $value = self::normalize($value);
        if ($value !== '') {
            $headers[] = "{$name}: {$value}";
        }
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new \InvalidArgumentException('OpenAI credential values must not contain line breaks.');
        }

        return $value;
    }
}
