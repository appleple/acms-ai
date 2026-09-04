<?php

namespace Acms\Plugins\AI;

use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\EnvCredential;
use Acms\Plugins\AI\Services\AI\Providers\Anthropic\AnthropicProvider;
use Acms\Plugins\AI\Services\AI\Providers\Gemini\GeminiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAi\OpenAiProvider;
use Acms\Plugins\AI\Services\AI\Providers\OpenAiCompat\OpenAiCompatProvider;
use Acms\Plugins\AI\Services\AI\Vision\MediaFieldGenerator;

class Hook
{
    /**
     * JSが更新された場合に、以前のバージョンで作られたキャッシュを使用しないようにキャッシュバスティングを行う
     * scriptタグでJSを読み込む際に、acmsのグローバル変数を経由する
     *
     * @param \Field $globalVars
     * @return void
     */
    public function extendsGlobalVars(&$globalVars)
    {
        $globalVars->set(
            'AI_JS',
            cacheBusting(
                '/' . DIR_OFFSET . 'extension/plugins/AI/bundle/acms-ai.js',
                SCRIPT_DIR . '/extension/plugins/AI/bundle/acms-ai.js'
            )
        );

        $globalVars->set(
            'AI_CSS',
            cacheBusting(
                '/' . DIR_OFFSET . 'extension/plugins/AI/bundle/acms-ai.css',
                SCRIPT_DIR . '/extension/plugins/AI/bundle/acms-ai.css'
            )
        );

        $this->setVisionFlags($globalVars);
        $this->setEnvFlags($globalVars);
    }

    /**
     * 認証情報が .env（環境変数）で供給されているかのフラグを '1'/'0' で渡す。
     * 管理画面（main.html）が各キーの入力欄を「.env 設定済み」表示へ出し分けるために使う
     * （env 供給時は入力欄も config[] も出さず、DB・HTML ソースへキーを残さない）。
     *
     * @param \Field $globalVars
     * @return void
     */
    private function setEnvFlags(&$globalVars)
    {
        $flags = [
            'AI_OPENAI_API_KEY_FROM_ENV' => OpenAiProvider::ENV_API_KEY,
            'AI_OPENAI_ORGANIZATION_ID_FROM_ENV' => OpenAiProvider::ENV_ORGANIZATION_ID,
            'AI_OPENAI_PROJECT_ID_FROM_ENV' => OpenAiProvider::ENV_PROJECT_ID,
            'AI_ANTHROPIC_API_KEY_FROM_ENV' => AnthropicProvider::ENV_API_KEY,
            'AI_GEMINI_API_KEY_FROM_ENV' => GeminiProvider::ENV_API_KEY,
            'AI_COMPAT_API_KEY_FROM_ENV' => OpenAiCompatProvider::ENV_API_KEY,
        ];
        foreach ($flags as $globalVar => $envKey) {
            $globalVars->set($globalVar, EnvCredential::isSet($envKey) ? '1' : '0');
        }
    }

    /**
     * メディア管理画面の AI 生成 UI（media/inject.html）が参照する有効フラグを '1'/'0' で渡す。
     *
     * inject.html はインライン JS を含むため BEGIN_MODULE で包めず（Template::render() が
     * JS の波括弧を変数とみなして壊す）、モジュール変数の代わりにグローバル変数で受け渡す。
     *
     * @param \Field $globalVars
     * @return void
     */
    private function setVisionFlags(&$globalVars)
    {
        $flags = array_fill_keys(array_keys(MediaFieldGenerator::VALID_CONFIG_KEYS), false);
        $master = false;
        try {
            $config = (new ServicesAI())->getConfig();
            $master = $config->get('ai_vision_valid') !== '';
            foreach (MediaFieldGenerator::VALID_CONFIG_KEYS as $field => $configKey) {
                $flags[$field] = $config->get($configKey) !== '';
            }
        } catch (\Throwable $e) {
            // config が読めない文脈では全て無効として扱う（UI を出さないだけで実害はない）。
        }

        $globalVars->set('AI_VISION_VALID', $master ? '1' : '0');
        foreach ($flags as $field => $enabled) {
            // 例: file_name → AI_VISION_VALID_FILENAME（config キーの接尾辞に合わせる）
            $suffix = strtoupper(str_replace('_', '', $field));
            $globalVars->set('AI_VISION_VALID_' . $suffix, $enabled ? '1' : '0');
        }
    }
}
