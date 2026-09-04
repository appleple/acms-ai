<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

/**
 * 環境変数（SCRIPT_DIR/.env。本体同梱の phpdotenv が $_ENV へ読み込む）による認証情報の供給。
 *
 * 管理画面フォームは API キーを value 属性で HTML ソースへ平文出力するため、
 * 管理画面を閲覧できる人にキーが見える（漏洩経路になる）。.env にキーを置けば
 * DB・画面にキーを出さずに運用できる。**環境変数が設定されていれば DB（config）より優先**し、
 * サーバー管理者がキーの管理方法を強制できるようにする。
 */
final class EnvCredential
{
    /**
     * 環境変数の値を返す。未設定・空なら $fallback（通常は config の値）を返す。
     */
    public static function get(string $envKey, string $fallback = ''): string
    {
        $value = $_ENV[$envKey] ?? '';

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * 環境変数が設定されているか（管理画面の「.env 設定済み」表示用）。
     */
    public static function isSet(string $envKey): bool
    {
        $value = $_ENV[$envKey] ?? '';

        return is_string($value) && $value !== '';
    }
}
