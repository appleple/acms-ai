<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit;

use Acms\Plugins\AI\ServiceProvider;
use Acms\Services\Common\InjectTemplate;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 拡張アプリの初期化（ServiceProvider::init）が、エントリー編集画面へ AI 機能のテンプレートを
 * 実際に差し込む配線を行うことを検証する。
 *
 * init() は Hook 登録と複数の InjectTemplate 差し込みを行う。ここでは差し込み先（admin-entry-field）に
 * 本プラグインのテンプレートパスが登録されることを、InjectTemplate の実レジストリで確認する
 * （パスの綴り間違いや PLUGIN_DIR 連結の破損を検知する回帰テスト）。
 */
final class ServiceProviderTest extends TestCase
{
    #[Test]
    #[TestDox('init はエントリー編集画面へ AI 機能テンプレートの差し込みを登録する')]
    public function initRegistersEntryFieldTemplate(): void
    {
        (new ServiceProvider())->init();

        $entries = InjectTemplate::singleton()->get('admin-entry-field');

        self::assertContains(PLUGIN_DIR . 'AI/template/admin/entry/edit.html', $entries);
    }
}
