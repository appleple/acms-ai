<?php

namespace Acms\Plugins\AI;

use ACMS_App;
use Storage;
use Acms\Services\Common\HookFactory;
use Acms\Services\Common\InjectTemplate;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '2.0.1';

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
    public $desc = 'AI機能を利用できます（a-blog cms 3.2.29以降）。';

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
        $assistantReady = $this->assistantReady();
        $mediaVisionReady = $this->mediaVisionReady();
        if ($assistantReady || $mediaVisionReady) {
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/loader.html');
        }
        if ($mediaVisionReady) {
            // CMS 標準の media.edit-modal.fields Fill にバンドル側から登録する。
            // 巨大なインライン JS や DOM 全体の MutationObserver は使わない。
            $inject->add('admin-main', PLUGIN_DIR . 'AI/template/admin/media/bootstrap.html');
        }

        if (ADMIN === 'app_' . $this->menu) {
            // 管理画面上部のパンくず（トピックパス）にページ名「AI機能」を表示する
            $inject->add('admin-topicpath', PLUGIN_DIR . 'AI/template/admin/topicpath.html');
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
        if (!sessionWithContribution(BID)) {
            return false;
        }

        try {
            $config = (new Services\AI())->getConfig();
            $provider = Services\AI\ProviderRegistry::withDefaults()->resolve($config);

            return $provider->isConfigured() && $config->get('ai_model') !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** メディア編集モーダルの画像解析が利用できるか。 */
    private function mediaVisionReady(): bool
    {
        if (!sessionWithContribution(BID)) {
            return false;
        }

        try {
            $serviceAi = new Services\AI();
            $config = $serviceAi->getConfig();
            $provider = Services\AI\ProviderRegistry::withDefaults()->resolve($config);

            return $config->get('ai_vision_enabled') === 'on'
                && $serviceAi->visionModel($config) !== ''
                && $provider->isConfigured()
                && $provider->supports(Capability::VisionInput)
                && $provider->supports(Capability::StructuredOutput);
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
        return Services\CmsVersionRequirement::currentIsSatisfied();
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
        $this->putConfig();
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
        if (!is_string($config)) {
            return;
        }

        $updated = Services\ConfigSystemBlock::remove($config);
        if ($updated !== $config) {
            Storage::put(CONFIG_FILE, $updated);
        }
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        $this->putConfig();

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

    /**
     * 拡張アプリの既定設定を private/config.system.yaml へ反映する。
     */
    private function putConfig(): void
    {
        $config = Storage::get(CONFIG_FILE);
        $pluginConfig = Storage::get(PLUGIN_LIB_DIR . $this->name . '/config.system.yaml');
        if (!is_string($pluginConfig) || trim($pluginConfig) === '') {
            return;
        }

        $config = is_string($config) ? $config : '';
        $updated = Services\ConfigSystemBlock::upsert($config, $pluginConfig);
        if ($updated !== $config) {
            Storage::put(CONFIG_FILE, $updated);
        }
    }
}
