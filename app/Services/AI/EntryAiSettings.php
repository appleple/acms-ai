<?php

namespace Acms\Plugins\AI\Services\AI;

use Field;

/**
 * エントリー編集で使う AI 機能の設定を、GET/POST 間で同じ規則に正規化する。
 */
final class EntryAiSettings
{
    private const DEFAULT_TITLE_PROMPT = "- Please give 5 suggestions.\n- Please answer in Japanese.";
    private const DEFAULT_TAG_PROMPT = 'Please answer in Japanese.';

    /** @var Field */
    private $config;

    public function __construct(Field $config)
    {
        $this->config = $config;
    }

    public function titleEnabled(): bool
    {
        return $this->enabled('ai_title_enabled');
    }

    public function tagEnabled(): bool
    {
        return $this->enabled('ai_tag_enabled');
    }

    public function entryEnabled(): bool
    {
        return $this->titleEnabled() || $this->tagEnabled();
    }

    public function titlePrompt(): string
    {
        return $this->prompt('ai_title_prompt', self::DEFAULT_TITLE_PROMPT);
    }

    public function tagPrompt(): string
    {
        return $this->prompt('ai_tag_prompt', self::DEFAULT_TAG_PROMPT);
    }

    private function enabled(string $key): bool
    {
        // この設定項目が存在しない旧インストールでは、従来どおり機能を有効にする。
        // 管理画面で明示的にチェックを外した場合は空のフィールド自体が残るため区別できる。
        return !$this->config->isExists($key) || $this->config->get($key) !== '';
    }

    private function prompt(string $key, string $fallback): string
    {
        $prompt = trim($this->config->get($key));

        return $prompt !== '' ? $prompt : $fallback;
    }
}
