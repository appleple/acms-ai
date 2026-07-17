<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Endpoints;

use Acms\Plugins\AI\Services\AI\Endpoints\ResponsesClient;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Responses API の応答から本文テキストを取り出す ResponsesClient::extractText を固定する。
 *
 * 応答構造は { output: [{ type: "message", content: [{ type: "output_text", text: "..." }] }] }。
 * 型が想定外・欠落・非オブジェクトの入力でも例外を投げず null を返す（外部 API 応答の堅牢な取り扱い）ことを保証する。
 */
final class ExtractTextTest extends TestCase
{
    /**
     * JSON 文字列を stdClass へデコードして返すヘルパ（実応答と同じ経路）。
     */
    private function decode(string $json): mixed
    {
        return json_decode($json);
    }

    #[Test]
    #[TestDox('message / output_text の本文テキストを取り出す')]
    public function extractsOutputText(): void
    {
        $response = $this->decode(
            '{"output":[{"type":"message","content":[{"type":"output_text","text":"こんにちは"}]}]}'
        );

        self::assertSame('こんにちは', ResponsesClient::extractText($response));
    }

    #[Test]
    #[TestDox('message 以外の output は読み飛ばし、最初の output_text を返す')]
    public function skipsNonMessageOutputs(): void
    {
        $response = $this->decode(
            '{"output":['
            . '{"type":"reasoning","content":[{"type":"output_text","text":"ignored"}]},'
            . '{"type":"message","content":[{"type":"output_text","text":"採用"}]}'
            . ']}'
        );

        self::assertSame('採用', ResponsesClient::extractText($response));
    }

    #[Test]
    #[TestDox('output_text 以外のコンテンツは読み飛ばす')]
    public function skipsNonOutputTextContent(): void
    {
        $response = $this->decode(
            '{"output":[{"type":"message","content":['
            . '{"type":"refusal","text":"だめ"},'
            . '{"type":"output_text","text":"本文"}'
            . ']}]}'
        );

        self::assertSame('本文', ResponsesClient::extractText($response));
    }

    #[Test]
    #[TestDox('output が無い応答は null を返す')]
    public function returnsNullWhenNoOutput(): void
    {
        self::assertNull(ResponsesClient::extractText($this->decode('{"status":"completed"}')));
    }

    #[Test]
    #[TestDox('output_text はあるが text が欠けていれば null を返す')]
    public function returnsNullWhenTextMissing(): void
    {
        $response = $this->decode(
            '{"output":[{"type":"message","content":[{"type":"output_text"}]}]}'
        );

        self::assertNull(ResponsesClient::extractText($response));
    }

    #[Test]
    #[TestDox('text が文字列でなければ null を返す')]
    public function returnsNullWhenTextNotString(): void
    {
        $response = $this->decode(
            '{"output":[{"type":"message","content":[{"type":"output_text","text":123}]}]}'
        );

        self::assertNull(ResponsesClient::extractText($response));
    }

    #[Test]
    #[TestDox('message に content が無い／空でも例外を投げず後続を探索する')]
    public function skipsMessageWithoutContent(): void
    {
        // content キーが無い message → 読み飛ばし、後続の message から取り出す。
        $response = $this->decode(
            '{"output":['
            . '{"type":"message"},'
            . '{"type":"message","content":[{"type":"output_text","text":"後続"}]}'
            . ']}'
        );
        self::assertSame('後続', ResponsesClient::extractText($response));

        // すべての message に有効な content が無ければ null。
        $empty = $this->decode('{"output":[{"type":"message","content":[]}]}');
        self::assertNull(ResponsesClient::extractText($empty));
    }

    #[Test]
    #[TestDox('オブジェクト以外（配列・文字列・null）の入力は null を返す')]
    public function returnsNullForNonObjectInput(): void
    {
        self::assertNull(ResponsesClient::extractText(null));
        self::assertNull(ResponsesClient::extractText('plain string'));
        self::assertNull(ResponsesClient::extractText([1, 2, 3]));
        self::assertNull(ResponsesClient::extractText($this->decode('[]')));
    }
}
