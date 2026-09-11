<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Providers\OpenAi;

/**
 * OpenAI通信の用途別タイムアウトを一元管理する。
 */
final class OpenAiCurlOptions
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const MODEL_LIST_TIMEOUT_SECONDS = 30;
    private const REQUEST_TIMEOUT_SECONDS = 180;
    private const STREAM_TIMEOUT_SECONDS = 600;
    private const STREAM_LOW_SPEED_BYTES_PER_SECOND = 1;
    private const STREAM_LOW_SPEED_SECONDS = 120;

    /** @return array<int, int> */
    public static function modelList(): array
    {
        return [
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::MODEL_LIST_TIMEOUT_SECONDS,
        ];
    }

    /** @return array<int, int> */
    public static function request(): array
    {
        return [
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
        ];
    }

    /** @return array<int, int> */
    public static function stream(): array
    {
        return [
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::STREAM_TIMEOUT_SECONDS,
            CURLOPT_LOW_SPEED_LIMIT => self::STREAM_LOW_SPEED_BYTES_PER_SECOND,
            CURLOPT_LOW_SPEED_TIME => self::STREAM_LOW_SPEED_SECONDS,
        ];
    }
}
