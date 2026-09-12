<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services;

/**
 * AI設定の資格情報を監査ログcontextから除外できるa-blog cms本体バージョンを要求する。
 */
final class CmsVersionRequirement
{
    public const MIN_VERSION = '3.2.29';

    public static function isSatisfied(?string $version): bool
    {
        return $version !== null
            && $version !== ''
            && version_compare($version, self::MIN_VERSION, '>=');
    }

    public static function currentIsSatisfied(): bool
    {
        if (!defined('VERSION')) {
            return false;
        }

        return self::isSatisfied((string) constant('VERSION'));
    }
}
