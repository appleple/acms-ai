<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Contracts;

/**
 * 生成リクエスト（プロバイダ非依存の入力）。
 *
 * プロバイダ実装がこの値をベンダ固有のペイロード（OpenAI Responses API の input/instructions/
 * text.format/previous_response_id など）へ変換する。
 */
final class GenerationRequest
{
    /**
     * @param string $model 使用モデル名
     * @param list<Message> $messages 会話メッセージ（順序どおり）
     * @param string|null $instructions system 指示（無ければ null）
     * @param array<string, mixed>|null $outputSchema 構造化出力の JSON Schema（自由記述なら null）
     * @param string|null $outputSchemaName 構造化出力スキーマの名前
     * @param string|null $continuationToken 会話継続の不透明トークン（OpenAI の previous_response_id 相当）
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly ?string $instructions = null,
        public readonly ?array $outputSchema = null,
        public readonly ?string $outputSchemaName = null,
        public readonly ?string $continuationToken = null,
    ) {
    }
}
