<?php

namespace Acms\Plugins\AI\Services;

/**
 * private/config.system.yaml 内の acms-ai 管理ブロックを安全に更新する。
 */
final class ConfigSystemBlock
{
    private const BLOCK_PATTERN = '~^#BEGIN_AIConfig[ \t]*\R.*?^#END_AIConfig[ \t]*(?:\R|\z)~ms';

    /**
     * 既存ブロックを置換し、存在しなければ末尾へ追加する。
     *
     * 重複ブロックがある場合は最初の位置へ新しいブロックを置き、残りを除去する。
     */
    public static function upsert(string $config, string $source): string
    {
        if (preg_match(self::BLOCK_PATTERN, $source, $matches) !== 1) {
            return $config;
        }

        $block = rtrim($matches[0], "\r\n") . "\n";
        $replaced = false;
        $result = preg_replace_callback(
            self::BLOCK_PATTERN,
            static function () use (&$replaced, $block): string {
                if ($replaced) {
                    return '';
                }
                $replaced = true;

                return $block;
            },
            $config
        );
        if ($result === null) {
            return $config;
        }
        if ($replaced) {
            return $result;
        }

        $config = rtrim($config, "\r\n");

        return ($config === '' ? '' : $config . "\n\n") . $block;
    }

    /**
     * acms-ai 管理ブロックだけをすべて除去する。
     */
    public static function remove(string $config): string
    {
        return preg_replace(self::BLOCK_PATTERN, '', $config) ?? $config;
    }
}
