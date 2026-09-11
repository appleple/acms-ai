<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Vision;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Field;

final class MediaFieldGenerator
{
    /** @var list<string> */
    public const ALLOWED = ['file_name', 'caption', 'alt', 'memo', 'tags'];

    public const DEFAULT_SYSTEM_PROMPT = 'あなたは画像のメタ情報を作成するアシスタントです。'
        . '指定されたキーだけを持つ JSON オブジェクトを1つ出力してください。';

    /** @var array<string, string> */
    public const DEFAULT_PROMPTS = [
        'alt' => '視覚障害のあるユーザー向けの簡潔で具体的な代替テキスト'
            . '（日本語、120文字以内、「画像」などの前置きや引用符・改行なし）',
        'caption' => '画像の短いキャプション（日本語、100文字以内、1文程度）',
        'memo' => '管理者向けの内部メモ（日本語、200文字以内、被写体・用途・キーワードなど）',
        'file_name' => '内容を表す英小文字スラッグ（半角英数字とハイフンのみ、拡張子なし、60文字以内）',
        'tags' => '画像内容を表す日本語タグの配列（5個程度、各タグは10文字以内の短い語）',
    ];

    /** @var array<string, string> */
    public const PROMPT_CONFIG_KEYS = [
        'alt' => 'ai_vision_prompt_alt',
        'caption' => 'ai_vision_prompt_caption',
        'memo' => 'ai_vision_prompt_memo',
        'file_name' => 'ai_vision_prompt_filename',
        'tags' => 'ai_vision_prompt_tags',
    ];

    /** @var array<string, string> */
    public const ENABLED_CONFIG_KEYS = [
        'alt' => 'ai_vision_enabled_alt',
        'caption' => 'ai_vision_enabled_caption',
        'memo' => 'ai_vision_enabled_memo',
        'file_name' => 'ai_vision_enabled_filename',
        'tags' => 'ai_vision_enabled_tags',
    ];

    /** @var array<string, int> */
    private const TEXT_LIMITS = ['alt' => 120, 'caption' => 100, 'memo' => 200];
    private const SLUG_MAX = 60;
    private const TAG_MAX = 10;
    private const TAG_COUNT_MAX = 10;

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public function enabledTargets(Field $config, array $requested): array
    {
        $targets = [];
        foreach ($requested as $target) {
            $target = trim($target);
            if (
                in_array($target, self::ALLOWED, true)
                && $config->get(self::ENABLED_CONFIG_KEYS[$target]) === 'on'
                && !in_array($target, $targets, true)
            ) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * @param list<string> $targets
     */
    public function buildRequest(Field $config, string $model, ContentPart $image, array $targets): GenerationRequest
    {
        if ($targets === [] || $image->type !== ContentPart::TYPE_IMAGE_DATA) {
            throw new \InvalidArgumentException('画像と生成対象を指定してください。');
        }

        return new GenerationRequest(
            $model,
            [Message::user(ContentPart::text($this->userPrompt($config, $targets)), $image)],
            $this->systemPrompt($config),
            $this->schemaFor($targets),
            'media_fields'
        );
    }

    /**
     * @param list<string> $targets
     * @return array<string, string|list<string>>
     */
    public function generate(AiProvider $provider, GenerationRequest $request, array $targets): array
    {
        $result = $provider->generateText($request);
        if ($result->text === null || trim($result->text) === '') {
            throw new \RuntimeException($result->errorMessage ?? '画像解析に失敗しました。');
        }

        try {
            $data = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('AI 応答の形式が正しくありません。');
        }
        if (!is_array($data) || array_diff(array_keys($data), $targets) !== []) {
            throw new \RuntimeException('AI 応答に要求外の項目が含まれています。');
        }

        $fields = [];
        foreach ($targets as $target) {
            if (!array_key_exists($target, $data)) {
                throw new \RuntimeException('AI 応答に必要な項目がありません。');
            }
            $value = $target === 'tags'
                ? $this->normalizeTags($data[$target])
                : $this->normalizeString($target, $data[$target]);
            if ($value === '' || $value === []) {
                throw new \RuntimeException('AI から有効な「' . $target . '」を取得できませんでした。');
            }
            $fields[$target] = $value;
        }

        return $fields;
    }

    private function systemPrompt(Field $config): string
    {
        $prompt = trim($config->get('ai_vision_system_prompt'));
        return $prompt === '' ? self::DEFAULT_SYSTEM_PROMPT : $prompt;
    }

    /** @param list<string> $targets */
    private function userPrompt(Field $config, array $targets): string
    {
        $lines = [];
        foreach ($targets as $target) {
            $instruction = trim($config->get(self::PROMPT_CONFIG_KEYS[$target]));
            $lines[] = '- "' . $target . '": ' . ($instruction === '' ? self::DEFAULT_PROMPTS[$target] : $instruction);
        }
        return "次の画像について、以下のキーを生成してください。\n" . implode("\n", $lines);
    }

    /**
     * @param list<string> $targets
     * @return array<string, mixed>
     */
    private function schemaFor(array $targets): array
    {
        $properties = [];
        foreach ($targets as $target) {
            $properties[$target] = $target === 'tags'
                ? ['type' => 'array', 'items' => ['type' => 'string']]
                : ['type' => 'string'];
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => $targets, 'additionalProperties' => false];
    }

    private function normalizeString(string $target, mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        if ($target === 'file_name') {
            $value = preg_replace('/\.(jpe?g|png|gif|webp)\z/i', '', strtolower(trim($raw))) ?? '';
            $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
            return trim(substr(trim($value, '-'), 0, self::SLUG_MAX), '-');
        }

        $value = trim($raw);
        if ($target !== 'memo') {
            $value = preg_replace('/\s*\R\s*/u', ' ', $value) ?? $value;
            $value = preg_replace('/^["\'“”「『](.*)["\'“”」』]$/u', '$1', $value) ?? $value;
        }
        return mb_substr(trim($value), 0, self::TEXT_LIMITS[$target]);
    }

    /** @return list<string> */
    private function normalizeTags(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                return [];
            }
            $tag = mb_substr(trim(str_replace(',', ' ', $tag)), 0, self::TAG_MAX);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
        return array_slice($tags, 0, self::TAG_COUNT_MAX);
    }
}
