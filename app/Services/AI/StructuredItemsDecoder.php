<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI;

/**
 * タイトル・タグ生成が共有する { items: [{ content }] } 応答を検証・正規化する。
 */
final class StructuredItemsDecoder
{
    public const INVALID_JSON = 'invalid_json';
    public const INVALID_ROOT = 'invalid_root';
    public const MISSING_ITEMS = 'missing_items';
    public const INVALID_ITEMS = 'invalid_items';
    public const INVALID_ITEM = 'invalid_item';

    public function decode(string $text): StructuredItemsDecodeResult
    {
        try {
            $decoded = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return StructuredItemsDecodeResult::failure(self::INVALID_JSON, $e->getCode());
        }

        if (!$decoded instanceof \stdClass) {
            return StructuredItemsDecodeResult::failure(self::INVALID_ROOT);
        }
        if (!property_exists($decoded, 'items')) {
            return StructuredItemsDecodeResult::failure(self::MISSING_ITEMS);
        }
        if (!is_array($decoded->items)) {
            return StructuredItemsDecodeResult::failure(self::INVALID_ITEMS);
        }

        $items = [];
        foreach ($decoded->items as $item) {
            if (!$item instanceof \stdClass || !isset($item->content) || !is_string($item->content)) {
                return StructuredItemsDecodeResult::failure(self::INVALID_ITEM);
            }
            // スキーマ外の値をクライアントへ渡さず、利用側との契約をここで固定する。
            $items[] = ['content' => $item->content];
        }

        return StructuredItemsDecodeResult::success($items);
    }
}
