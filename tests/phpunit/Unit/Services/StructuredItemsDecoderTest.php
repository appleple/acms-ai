<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI\StructuredItemsDecoder;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

final class StructuredItemsDecoderTest extends TestCase
{
    #[Test]
    #[TestDox('候補配列を検証しcontent以外の値を除いて返す')]
    public function decodesAndNormalizesItems(): void
    {
        $result = (new StructuredItemsDecoder())->decode(
            '{"items":[{"content":"候補A","unexpected":"secret"},{"content":"候補B"}]}'
        );

        self::assertTrue($result->succeeded());
        self::assertSame([
            ['content' => '候補A'],
            ['content' => '候補B'],
        ], $result->items);
        self::assertNull($result->failureReason);
    }

    #[Test]
    #[TestDox('不正なJSONは本文を保持せずエラーコードだけを返す')]
    public function reportsInvalidJsonWithoutKeepingBody(): void
    {
        $result = (new StructuredItemsDecoder())->decode('{"items":[secret]}');

        self::assertFalse($result->succeeded());
        self::assertSame(StructuredItemsDecoder::INVALID_JSON, $result->failureReason);
        self::assertSame(JSON_ERROR_SYNTAX, $result->jsonErrorCode);
        self::assertSame([], $result->items);
    }

    #[Test]
    #[TestDox('ルート・items・各候補の契約違反を区別する')]
    public function distinguishesSchemaFailures(): void
    {
        $decoder = new StructuredItemsDecoder();
        $cases = [
            '[]' => StructuredItemsDecoder::INVALID_ROOT,
            '{}' => StructuredItemsDecoder::MISSING_ITEMS,
            '{"other":[]}' => StructuredItemsDecoder::MISSING_ITEMS,
            '{"items":{}}' => StructuredItemsDecoder::INVALID_ITEMS,
            '{"items":[{"content":null}]}' => StructuredItemsDecoder::INVALID_ITEM,
        ];

        foreach ($cases as $json => $expectedReason) {
            $result = $decoder->decode($json);
            self::assertFalse($result->succeeded(), $json);
            self::assertSame($expectedReason, $result->failureReason, $json);
        }
    }
}
