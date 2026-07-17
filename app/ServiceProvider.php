<?php

namespace Acms\Plugins\AI;

use ACMS_App;
use Acms\Services\Common\HookFactory;
use Acms\Services\Common\InjectTemplate;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '1.1.2';

    /**
     * @var string
     */
    public $name = 'AI';

    /**
     * @var string
     */
    public $author = 'com.appleple';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var false|string
     */
    public $menu = 'ai_index';

    /**
     * @var string
     */
    public $desc = 'ChatGPTを利用したAI機能が使えます。';

    /**
     * サービスの初期処理
     *
     * @return void
     */
    public function init()
    {
        // Hook追加
        $hook = HookFactory::singleton();
        $hook->attach('AIHook', new Hook());

        // テンプレート追加
        $inject = InjectTemplate::singleton();
        $inject->add('admin-module-select', PLUGIN_DIR . 'AI/template/module/select.html');
        $inject->add('admin-module-config-Sample', PLUGIN_DIR . 'AI/template/config.html');
        $inject->add('admin-entry-field', PLUGIN_DIR . 'AI/template/admin/entry/edit.html');

        if (ADMIN === 'app_' . $this->menu) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/main.html');
        }
    }

    /**
     * インストールする前の環境チェック処理
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * インストールするときの処理
     * データベーステーブルの初期化など
     *
     * @return void
     */
    public function install()
    {
    }

    /**
     * アンインストールするときの処理
     * データベーステーブルの始末など
     *
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * 有効化するときの処理
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化するときの処理
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
