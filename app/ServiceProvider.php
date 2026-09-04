<?php

namespace Acms\Plugins\AI;

use ACMS_App;
use Storage;
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

        // 全管理画面共通ローダー。<acms-ai-assistant-button> がある画面だけ本体バンドルを
        // 遅延ロードし、エントリー編集以外の管理画面でも AI アシスタントボタンを使えるようにする。
        // 認証情報・モデルが未設定なら注入しない（判定は PHP 側に閉じる）。
        if ($this->assistantReady()) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/loader.html');
        }

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
     * AI アシスタント（テキスト生成）が利用できる状態か（認証情報・モデル）。
     *
     * @return bool
     */
    private function assistantReady()
    {
        try {
            $config = (new Services\AI())->getConfig();
            $provider = Services\AI\ProviderRegistry::withDefaults()->resolve($config);

            return $provider->isConfigured() && $config->get('ai_model') !== '';
        } catch (\Throwable $e) {
            return false;
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
     * インストールするときの処理。
     * 既定プロンプト等（app/config.system.yaml の #BEGIN_AIConfig〜#END_AIConfig）を
     * 本体の設定ファイル（private/config.system.yaml）へ追記する。
     * これにより管理画面の textarea に既定プロンプトが編集可能な形で表示され、
     * 未保存でも生成時に既定値が使われる（Favorite プラグインと同じ方式）。
     *
     * @return void
     */
    public function install()
    {
        $config = Storage::get(CONFIG_FILE);
        $pluginConfig = Storage::get(PLUGIN_LIB_DIR . $this->name . '/config.system.yaml');
        if (!$pluginConfig) {
            return;
        }
        if (preg_match('/(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)/', $config)) {
            // 既存ブロックを置換（再インストール時の二重記述を防ぐ）
            Storage::put(
                CONFIG_FILE,
                preg_replace('/(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)/', $pluginConfig, $config)
            );
        } else {
            Storage::put(CONFIG_FILE, $config . "\n" . $pluginConfig);
        }
    }

    /**
     * アンインストールするときの処理。
     * install() が本体の設定ファイルへ追記した設定ブロックを取り除く。
     *
     * @return void
     */
    public function uninstall()
    {
        $config = Storage::get(CONFIG_FILE);
        if ($config && preg_match('/(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)/', $config)) {
            Storage::put(
                CONFIG_FILE,
                preg_replace('/\n?(#BEGIN_AIConfig)[\s\S]*(#END_AIConfig)\n?/', "\n", $config)
            );
        }
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
