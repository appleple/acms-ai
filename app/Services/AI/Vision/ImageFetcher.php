<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Vision;

use Acms\Plugins\AI\Services\AI\EnvCredential;

/**
 * 同一サイト上のメディア画像 URL を取得し、vision 用の data URL へ変換する。
 *
 * メディアライブラリの画像は公開 URL で配信されているため HTTP GET で取得する
 * （同一オリジン検証は呼び出し側 {@see \Acms\Plugins\AI\POST\AI\GenerateMediaFields} の責務）。
 * vision API が扱える形式（jpeg / png / gif / webp）とサイズ上限に制限する。
 */
class ImageFetcher
{
    /** vision API が受け付ける MIME タイプ */
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** 取得を許可する最大バイト数（API 側の上限・メモリ保護） */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * TLS 証明書検証をスキップする環境変数（ローカル開発の自己署名証明書用）。
     * 本番で無効化しないよう、既定は検証有効。
     */
    public const ENV_INSECURE_SSL = 'ACMS_AI_INSECURE_LOCAL_SSL';

    /**
     * 画像 URL を取得して data URL を返す。
     *
     * @throws \RuntimeException 取得失敗・リダイレクト・非対応形式・サイズ超過時
     */
    public function fetchAsDataUrl(string $url): string
    {
        [$status, $body] = $this->httpGetBinary($url);
        if ($status >= 300 && $status < 400) {
            // オープンリダイレクト経由で意図しない先を取得しないよう、リダイレクトは追わず拒否する。
            throw new \RuntimeException('画像URLのリダイレクトは許可されていません');
        }
        if ($status >= 400 || $body === '') {
            throw new \RuntimeException('画像の取得に失敗しました (HTTP ' . $status . ')');
        }
        // 受信中の中断（httpGetBinary 内）に加えた最終防衛。テスト差し替え時にも上限を保証する
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('画像サイズが大きすぎます（8MB 以下にしてください）');
        }

        $mime = $this->detectMime($body);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new \RuntimeException('対応していない画像形式です: ' . $mime);
        }

        return DataUrl::build($mime, base64_encode($body));
    }

    /**
     * バイト列から MIME タイプを判定する（拡張子や Content-Type は信用しない）。
     */
    private function detectMime(string $body): string
    {
        if (class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        $info = @getimagesizefromstring($body);
        if (is_array($info)) {
            return $info['mime'];
        }

        return 'application/octet-stream';
    }

    /**
     * 画像を GET し [HTTPステータス, ボディ] を返す。curl 依存の I/O 境界。
     * テストではこのメソッドを差し替えて取得後の検証ロジックを検証する。
     *
     * 同一オリジン（自サイト）の画像取得専用のため、リダイレクトは追わない。
     * TLS 証明書は既定で検証する。ローカル開発（自己署名証明書）では
     * .env の {@see self::ENV_INSECURE_SSL} を 1 にした場合のみ検証をスキップできる。
     * 受信中に MAX_BYTES を超えた時点で転送を中断し、巨大応答によるメモリ消費を防ぐ。
     *
     * @return array{0: int, 1: string}
     * @throws \RuntimeException cURL 実行に失敗した場合
     * @codeCoverageIgnore 実通信（curl）の I/O 境界。決定的なユニット検証ができないため実機/E2E で担保する。
     */
    protected function httpGetBinary(string $url): array
    {
        $insecureLocal = EnvCredential::get(self::ENV_INSECURE_SSL) === '1';
        $body = '';
        $overflow = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => !$insecureLocal,
            CURLOPT_SSL_VERIFYHOST => $insecureLocal ? 0 : 2,
            // 受信中に上限を超えたら中断する（全量受信によるメモリ消費を防ぐ）
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$overflow): int {
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    $overflow = true;
                    return -1;
                }
                return strlen($chunk);
            },
        ]);
        $succeeded = curl_exec($ch);
        if ($overflow) {
            throw new \RuntimeException('画像サイズが大きすぎます（8MB 以下にしてください）');
        }
        if ($succeeded !== true) {
            throw new \RuntimeException('画像の取得に失敗しました: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $body];
    }
}
