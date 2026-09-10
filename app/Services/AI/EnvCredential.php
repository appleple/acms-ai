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
    /** @var array<string, list<string>> config キーごとの環境変数名（先頭を優先する）。 */
    private const CONFIG_ENV_KEYS = [
        'ai_api_key' => ['ACMS_AI_OPENAI_API_KEY'],
        'ai_organization_id' => ['ACMS_AI_OPENAI_ORGANIZATION_ID'],
        'ai_project_id' => ['ACMS_AI_OPENAI_PROJECT_ID'],
        'ai_anthropic_api_key' => ['ACMS_AI_ANTHROPIC_API_KEY'],
        'ai_gemini_api_key' => ['ACMS_AI_GEMINI_API_KEY'],
        'ai_compat_api_key' => ['ACMS_AI_COMPAT_API_KEY', 'ACMS_AI_SAKURA_API_KEY'],
    ];

    /**
     * 環境変数の値を返す。未設定・空なら $fallback（通常は config の値）を返す。
     */
    public static function get(string $envKey, string $fallback = ''): string
    {
        $value = $_ENV[$envKey] ?? '';
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : $fallback;
    }

    /**
     * 環境変数が設定されているか（管理画面の「.env 設定済み」表示用）。
     */
    public static function isSet(string $envKey): bool
    {
        return self::get($envKey) !== '';
    }

    /**
     * config キーに対応する環境変数を優先順に解決する。
     */
    public static function getForConfig(string $configKey, string $fallback = ''): string
    {
        foreach (self::CONFIG_ENV_KEYS[$configKey] ?? [] as $envKey) {
            $value = self::get($envKey);
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * config キーに対応する環境変数が1つ以上設定されているか。
     */
    public static function isSetForConfig(string $configKey): bool
    {
        return self::getForConfig($configKey) !== '';
    }
}
