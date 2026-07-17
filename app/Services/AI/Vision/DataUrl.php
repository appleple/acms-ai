<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Vision;

/**
 * data URL（data:<mime>;base64,<data>）の生成・解析ユーティリティ。
 *
 * メディア画像は認証下・ローカル環境などプロバイダから直接取得できない URL であることが多いため、
 * サーバー側で取得して data URL として {@see \Acms\Plugins\AI\Services\AI\Contracts\ContentPart::image()}
 * へ渡す。プロバイダ実装は必要に応じてここで base64 と MIME に分解し、各ベンダの形式
 * （Anthropic の base64 source / Gemini の inlineData 等）へ変換する。
 */
final class DataUrl
{
    /**
     * base64 の data URL を組み立てる。
     */
    public static function build(string $mimeType, string $base64): string
    {
        return 'data:' . $mimeType . ';base64,' . $base64;
    }

    /**
     * data URL を MIME タイプと base64 データへ分解する。data URL でなければ null。
     *
     * @return array{mimeType: string, data: string}|null
     */
    public static function parse(string $url): ?array
    {
        if (preg_match('#\Adata:([-\w.+]+/[-\w.+]+);base64,#', $url, $matches) !== 1) {
            return null;
        }
        $data = substr($url, strlen($matches[0]));
        if ($data === '') {
            return null;
        }

        return ['mimeType' => $matches[1], 'data' => $data];
    }
}
