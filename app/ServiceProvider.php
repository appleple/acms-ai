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

        // メディア管理画面では、画像から各フィールドを生成する AI 生成 UI を注入する。
        // 選択中のプロバイダが vision に対応し、設定が揃っている場合のみ（判定は PHP 側に閉じる）。
        if (ADMIN === 'media_index' && $this->visionReady()) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/media/inject.html');
        }

        if (ADMIN === 'app_' . $this->menu) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/main.html');
        }
    }

    /**
     * メディア AI 生成が利用できる状態か（親スイッチ・認証情報・モデル・vision 対応）。
     *
     * @return bool
     */
    private function visionReady()
    {
        try {
            $serviceAi = new Services\AI();
            $config = $serviceAi->getConfig();
            if ($config->get('ai_vision_valid') === '') {
                return false;
            }
            $provider = Services\AI\ProviderRegistry::withDefaults()->resolve($config);

            return $provider->isConfigured()
                && $serviceAi->visionModel($config) !== ''
                && $provider->supports(Services\AI\Contracts\Capability::VisionInput);
        } catch (\Throwable $e) {
            return false;
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
