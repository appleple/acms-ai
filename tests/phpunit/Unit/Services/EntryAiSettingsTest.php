<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\EntryAiSettings;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class EntryAiSettingsTest extends TestCase
{
    #[Test]
    #[TestDox('有効フラグが存在しない旧インストールでは従来どおり両機能を有効にする')]
    public function enablesEntryFeaturesWhenFlagsAreMissing(): void
    {
        $settings = new EntryAiSettings(new Field());

        self::assertTrue($settings->titleEnabled());
        self::assertTrue($settings->tagEnabled());
        self::assertTrue($settings->entryEnabled());
    }

    #[Test]
    #[TestDox('管理画面で空として保存された機能だけを無効にする')]
    public function disablesOnlyExplicitlyEmptyFeatures(): void
    {
        $config = new Field();
        $config->set('ai_title_enabled', '');
        $config->set('ai_tag_enabled', 'on');

        $settings = new EntryAiSettings($config);

        self::assertFalse($settings->titleEnabled());
        self::assertTrue($settings->tagEnabled());
        self::assertTrue($settings->entryEnabled());
    }

    #[Test]
    #[TestDox('空のプロンプトは安全な内蔵既定値へフォールバックする')]
    public function fallsBackForEmptyPrompts(): void
    {
        $config = new Field();
        $config->set('ai_title_prompt', " \n ");
        $config->set('ai_tag_prompt', '');

        $settings = new EntryAiSettings($config);

        self::assertStringContainsString('5 suggestions', $settings->titlePrompt());
        self::assertSame('Please answer in Japanese.', $settings->tagPrompt());
    }

    #[Test]
    #[TestDox('保存済みプロンプトは前後の空白を除いて返す')]
    public function returnsConfiguredPrompts(): void
    {
        $config = new Field();
        $config->set('ai_title_prompt', '  title prompt  ');
        $config->set('ai_tag_prompt', "\ntag prompt\n");

        $settings = new EntryAiSettings($config);

        self::assertSame('title prompt', $settings->titlePrompt());
        self::assertSame('tag prompt', $settings->tagPrompt());
    }
}
