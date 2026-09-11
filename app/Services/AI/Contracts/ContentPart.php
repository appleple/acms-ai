<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * メッセージを構成する 1 つのコンテンツ断片（プロバイダ非依存）。
 *
 * テキスト、画像 URL、または信頼済みの画像データのいずれか。OpenAI の input_text/output_text/input_image のような
 * ベンダ固有の型名への変換は各プロバイダ実装が担う（ここでは持たない）。
 */
final class ContentPart
{
    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_IMAGE_DATA = 'image_data';

    private function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly ?string $mimeType = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(self::TYPE_TEXT, $text);
    }

    public static function image(string $url): self
    {
        return new self(self::TYPE_IMAGE, $url);
    }

    /**
     * CMS が権限検査済みストレージから読み込んだ画像を表す。
     *
     * URL と分けることで、プロバイダ実装が任意 URL をサーバー側で取得せず、
     * ベンダ固有の base64 形式へ安全に変換できる。
     */
    public static function imageData(string $mimeType, string $base64): self
    {
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \InvalidArgumentException('対応していない画像形式です。');
        }
        if ($base64 === '' || base64_decode($base64, true) === false) {
            throw new \InvalidArgumentException('画像データが正しくありません。');
        }

        return new self(self::TYPE_IMAGE_DATA, $base64, $mimeType);
    }

    public function asDataUrl(): string
    {
        if ($this->type !== self::TYPE_IMAGE_DATA || $this->mimeType === null) {
            throw new \LogicException('画像データではありません。');
        }

        return 'data:' . $this->mimeType . ';base64,' . $this->value;
    }
}
