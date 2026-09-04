<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Logger;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Logging\AuditLogSanitizer;
use Acms\Plugins\AI\Services\AI\ProviderRegistry;
use Acms\Plugins\AI\Services\AI\Vision\ImageFetcher;
use Acms\Plugins\AI\Services\AI\Vision\MediaFieldGenerator;

/**
 * ACMS_POST_AI_GenerateMediaFields
 *
 * メディア画像から複数フィールド（ファイル名・キャプション・代替テキスト・メモ・タグ）を
 * AI でまとめて生成する Ajax エンドポイント（メディア管理画面の AI 生成 UI から呼ばれる）。
 *
 * 要求された項目のみを 1 回の vision 呼び出し（構造化出力）で JSON 生成して返す。
 * API キーはサーバー側 config から読むため JS には露出しない。画像は同一オリジンの URL のみ
 * 受け付け、サーバー側で取得して data URL としてプロバイダへ渡す（プロバイダから直接取得
 * できない認証下・ローカル環境でも動作させるため）。
 * ドメインロジックは {@see MediaFieldGenerator} に分離してある。
 */
class GenerateMediaFields extends ACMS_POST
{
    public function post()
    {
        // 監査ログの req_body へ画像 URL 等が平文で残らないようにする
        AuditLogSanitizer::protectRequestBody();

        // 投稿者以上（メディア管理は投稿者から利用できるため、他の AI エンドポイントと同じ基準に揃える）
        if (!sessionWithContribution()) {
            $this->respond(403, '権限がありません', ['reason' => 'permission_denied']);
            return $this->Post;
        }

        // CSRF
        if ($this->csrfTokenExists() && !$this->checkCsrfToken()) {
            $this->respond(403, '不正なトークンです', ['reason' => 'invalid_csrf_token']);
            return $this->Post;
        }

        $imageUrl = trim($this->Post->get('image_url', ''));
        if ($imageUrl === '') {
            $this->respond(400, '画像 URL が指定されていません', ['reason' => 'missing_image_url']);
            return $this->Post;
        }
        if (!$this->isSameOrigin($imageUrl)) {
            $this->respond(400, 'このサイト上の画像のみ対象にできます', [
                'reason' => 'image_url_not_same_origin',
                'image_url_host' => (string) parse_url($imageUrl, PHP_URL_HOST),
            ]);
            return $this->Post;
        }

        $config = (new ServicesAI())->getConfig();

        // 親スイッチ（管理画面「メディア プロンプト設定」）
        if ($config->get('ai_vision_valid') === '') {
            $this->respond(403, 'メディアAI生成は管理画面で有効化されていません。', ['reason' => 'feature_disabled']);
            return $this->Post;
        }

        // 生成対象（カンマ区切り）。許可リストと管理者の有効設定で絞り込む。
        $generator = new MediaFieldGenerator();
        $requested = explode(',', $this->Post->get('targets', ''));
        $targets = $generator->enabledTargets($config, $requested);
        if ($targets === []) {
            $this->respond(400, '有効な生成項目がありません（管理画面のメディア プロンプト設定で有効化してください）', [
                'reason' => 'no_enabled_targets',
                'requested_targets' => implode(',', $requested),
            ]);
            return $this->Post;
        }

        try {
            $provider = ProviderRegistry::withDefaults()->resolve($config);
            // 画像解析モデル（ai_vision_model）優先・空なら通常モデル（ai_model）へフォールバック。
            $model = (new ServicesAI())->visionModel($config);
            if (!$provider->isConfigured() || $model === '') {
                $this->respond(400, 'APIキーまたはモデルの設定がありません。', ['reason' => 'not_configured']);
                return $this->Post;
            }
            if (!$provider->supports(Capability::VisionInput)) {
                $this->respond(400, '選択中のAIプロバイダは画像解析（vision）に対応していません', [
                    'reason' => 'unsupported_provider',
                    'provider' => $provider->id(),
                ]);
                return $this->Post;
            }

            $dataUrl = $this->imageFetcher()->fetchAsDataUrl($imageUrl);
            $fields = $generator->generate($provider, $config, $model, $dataUrl, $targets);
        } catch (\RuntimeException $e) {
            // 画像取得・生成・解析の失敗。利用者向けメッセージをそのまま返す（機微情報は含まない）。
            $this->respond(400, $e->getMessage(), ['reason' => 'generation_failed', 'targets' => $targets]);
            return $this->Post;
        } catch (\Throwable $e) {
            Logger::error('【AI plugin】 メディアAI生成で予期しないエラーが発生しました', Common::exceptionArray($e));
            $this->respond(500, '画像解析に失敗しました。', ['reason' => 'unexpected_error', 'targets' => $targets]);
            return $this->Post;
        }

        $this->respond(200, null, [], ['fields' => $fields]);
        return $this->Post;
    }

    /**
     * ImageFetcher を生成する。テストで差し替えられるようメソッドに切り出す。
     */
    protected function imageFetcher(): ImageFetcher
    {
        return new ImageFetcher();
    }

    /**
     * URL が現在のサイトと同一オリジンか判定する（SSRF 対策）。
     */
    private function isSameOrigin(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            // ホスト無し = 相対パス。「/」始まり（かつ「//」でない）のみ許可する。
            return str_starts_with($url, '/') && !str_starts_with($url, '//');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $currentHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $currentHost = preg_replace('/:\d+$/', '', $currentHost) ?? $currentHost;

        return strcasecmp($host, $currentHost) === 0;
    }

    /**
     * JSON で応答して終了する。エラー時は運用ログに理由を残す（本文・画像データは含めない）。
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $body
     */
    private function respond(int $code, ?string $error, array $context = [], array $body = []): void
    {
        if ($code >= 400 && $error !== null) {
            Logger::notice('【AI plugin】 メディアAI生成に失敗しました: ' . $error, $context);
            $body = ['error' => $error];
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        die();
    }
}
