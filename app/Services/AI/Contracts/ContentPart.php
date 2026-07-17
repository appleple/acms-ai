<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * メッセージを構成する 1 つのコンテンツ断片（プロバイダ非依存）。
 *
 * テキストか画像 URL のいずれか。OpenAI の input_text/output_text/input_image のような
 * ベンダ固有の型名への変換は各プロバイダ実装が担う（ここでは持たない）。
 */
final class ContentPart
{
    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';

    private function __construct(
        public readonly string $type,
        public readonly string $value,
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
}
