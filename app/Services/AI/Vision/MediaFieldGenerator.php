<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Services\AI\Vision;

use Acms\Plugins\AI\Services\AI\Contracts\AiProvider;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationRequest;
use Acms\Plugins\AI\Services\AI\Contracts\Message;
use Field;

/**
 * メディア画像から複数フィールド（ファイル名・キャプション・代替テキスト・メモ・タグ）を
 * 1 回の vision 呼び出しでまとめて生成するドメインロジック。
 *
 * プロンプトの組み立て（既定文と config 上書き）、構造化出力スキーマの生成、応答の正規化
 * （スラッグ化・引用符除去・タグ配列の整形・安全上限）を担う。HTTP・権限・画像取得は
 * 呼び出し側（{@see \Acms\Plugins\AI\POST\AI\GenerateMediaFields}）の責務。
 *
 * 生成は {@see AiProvider} の構造化出力（outputSchema）を使う。要求したキーだけを持つ
 * JSON オブジェクトが返る前提だが、プロバイダ・モデルの揺れに備えて応答の正規化で防御する。
 */
class MediaFieldGenerator
{
    /** 生成可能なフィールド */
    public const ALLOWED = ['file_name', 'caption', 'alt', 'memo', 'tags'];

    /** システムプロンプトの既定文（設定が空のとき使用） */
    public const DEFAULT_SYSTEM_PROMPT = 'あなたは画像のメタ情報を作成するアシスタントです。'
        . '指定されたキーだけを持つ JSON オブジェクトを1つだけ出力してください。'
        . 'コードフェンス・説明文・前後の文章は一切付けず、JSON のみを返します。';

    /** 項目別の指示文の既定（設定が空のとき使用）。JSON キーはコード側で付与する。 */
    public const DEFAULT_PROMPTS = [
        'alt' => '視覚障害のあるユーザー向けの簡潔で具体的な代替テキスト'
            . '（日本語、120文字以内、「画像」などの前置きや引用符・改行なし）',
        'caption' => '画像の短いキャプション（日本語、100文字以内、1文程度）',
        'memo' => '管理者向けの内部メモ（日本語、200文字以内、被写体・用途・キーワードなど）',
        'file_name' => '内容を表す英小文字スラッグ（半角英数字とハイフンのみ。拡張子は書かない／'
            . '元の拡張子は自動で保持。60文字以内、例: business-woman-office）',
        'tags' => '画像内容を表す日本語タグの配列（5個程度、各タグは10文字以内の短い語）',
    ];

    /** 各項目の指示文を保持する config キー */
    public const PROMPT_CONFIG_KEYS = [
        'alt' => 'ai_vision_prompt_alt',
        'caption' => 'ai_vision_prompt_caption',
        'memo' => 'ai_vision_prompt_memo',
        'file_name' => 'ai_vision_prompt_filename',
        'tags' => 'ai_vision_prompt_tags',
    ];

    /** 各項目の有効・無効（管理者設定）を保持する config キー */
    public const VALID_CONFIG_KEYS = [
        'alt' => 'ai_vision_valid_alt',
        'caption' => 'ai_vision_valid_caption',
        'memo' => 'ai_vision_valid_memo',
        'file_name' => 'ai_vision_valid_filename',
        'tags' => 'ai_vision_valid_tags',
    ];

    /** 暴走した応答を防ぐための固定の安全上限（ユーザー向けの文字数指定はプロンプトで行う） */
    private const SAFETY_TEXT_MAX = 1000;
    private const SAFETY_SLUG_MAX = 80;
    private const SAFETY_TAG_MAX = 30;
    private const SAFETY_TAG_COUNT = 20;

    /**
     * 要求された項目を、許可リストと管理者の有効設定（ai_vision_valid_*）で絞り込む。
     *
     * @param list<string> $requested
     * @return list<string>
     */
    public function enabledTargets(Field $config, array $requested): array
    {
        $targets = array_values(array_unique(array_filter(
            array_map('trim', $requested),
            static fn(string $target): bool => in_array($target, self::ALLOWED, true)
        )));

        return array_values(array_filter(
            $targets,
            static fn(string $target): bool => $config->get(self::VALID_CONFIG_KEYS[$target]) !== ''
        ));
    }

    /**
     * 画像（data URL）から要求項目を生成し、正規化済みの連想配列を返す。
     *
     * @param list<string> $targets self::ALLOWED のサブセット
     * @return array<string, string|list<string>>
     * @throws \RuntimeException 生成失敗・応答解析失敗・有効な値が得られない場合
     */
    public function generate(
        AiProvider $provider,
        Field $config,
        string $model,
        string $imageDataUrl,
        array $targets
    ): array {
        $request = new GenerationRequest(
            $model,
            [Message::user(ContentPart::text($this->buildUserPrompt($config, $targets)), ContentPart::image($imageDataUrl))],
            $this->systemPrompt($config),
            $this->schemaFor($targets),
            'media_fields'
        );

        $result = $provider->generateText($request);
        $text = $result->text;
        if ($text === null || $text === '') {
            throw new \RuntimeException($result->errorMessage ?? '画像解析に失敗しました。');
        }

        $data = $this->decodeJson($text);
        if ($data === null) {
            throw new \RuntimeException('AI 応答の解析に失敗しました。');
        }

        $fields = $this->normalizeFields($data, $targets);
        if ($fields === []) {
            throw new \RuntimeException('AI から有効な値を取得できませんでした。');
        }

        return $fields;
    }

    /**
     * システムプロンプト（config 上書き・空なら既定）。
     */
    private function systemPrompt(Field $config): string
    {
        $prompt = trim($config->get('ai_vision_system_prompt'));

        return $prompt === '' ? self::DEFAULT_SYSTEM_PROMPT : $prompt;
    }

    /**
     * 要求項目ごとの指示文（config 上書き・空なら既定）を列挙したユーザープロンプトを組み立てる。
     *
     * @param list<string> $targets
     */
    private function buildUserPrompt(Field $config, array $targets): string
    {
        $lines = [];
        foreach ($targets as $target) {
            $instruction = trim($config->get(self::PROMPT_CONFIG_KEYS[$target]));
            if ($instruction === '') {
                $instruction = self::DEFAULT_PROMPTS[$target];
            }
            $lines[] = '- "' . $target . '": ' . $instruction;
        }

        return "次の画像について、以下のキーを含む JSON オブジェクトを生成してください。\n" . implode("\n", $lines);
    }

    /**
     * 要求項目に応じた構造化出力スキーマを組み立てる（tags のみ文字列配列、他は文字列）。
     *
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

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $targets,
            'additionalProperties' => false,
        ];
    }

    /**
     * 応答テキストから JSON オブジェクトを取り出してデコードする（コードフェンス・前後ゴミ対応）。
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/\A```[a-zA-Z]*\s*|\s*```\z/u', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 応答データから要求項目だけを取り出し、項目ごとに正規化する。
     *
     * @param array<string, mixed> $data
     * @param list<string> $targets
     * @return array<string, string|list<string>>
     */
    private function normalizeFields(array $data, array $targets): array
    {
        $fields = [];
        foreach ($targets as $target) {
            if ($target === 'tags') {
                $tags = $this->normalizeTags($data['tags'] ?? null);
                if ($tags !== []) {
                    $fields['tags'] = $tags;
                }
                continue;
            }
            if (!isset($data[$target]) || !is_string($data[$target])) {
                continue;
            }
            $value = match ($target) {
                'file_name' => $this->slugify($data[$target]),
                'alt', 'caption' => $this->normalizeText($data[$target]),
                // メモは改行を許容（前後トリムと安全上限のみ）
                default => mb_substr(trim($data[$target]), 0, self::SAFETY_TEXT_MAX),
            };
            if ($value !== '') {
                $fields[$target] = $value;
            }
        }

        return $fields;
    }

    /**
     * 代替テキスト・キャプション向けの整形（前後空白・囲み引用符の除去、改行→空白、安全上限）。
     */
    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s*\R\s*/u', ' ', $text) ?? $text;
        $text = preg_replace('/^["“”\'「『](.*)["“”\'」』]$/u', '$1', $text) ?? $text;
        $text = trim($text);

        return mb_substr($text, 0, self::SAFETY_TEXT_MAX);
    }

    /**
     * ファイル名スラッグへ正規化（半角英小文字・数字・ハイフンのみ、拡張子なし）。
     */
    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        // 拡張子っぽい末尾を除去（保険）
        $text = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '', $text) ?? $text;
        // 英数とハイフン以外をハイフンに
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        $text = trim($text, '-');
        if ($text === '') {
            return '';
        }
        $text = mb_substr($text, 0, self::SAFETY_SLUG_MAX);

        return trim($text, '-');
    }

    /**
     * タグ配列を正規化する（文字列のみ・前後空白除去・空除去・長さ/件数制限・重複除去）。
     *
     * @return list<string>
     */
    private function normalizeTags(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            // タグに使えない記号（カンマ等）を除去: media_label がカンマ区切りのため
            $tag = trim(str_replace(',', ' ', trim($tag)));
            if ($tag === '') {
                continue;
            }
            $tags[] = mb_substr($tag, 0, self::SAFETY_TAG_MAX);
        }
        $tags = array_values(array_unique($tags));

        return array_slice($tags, 0, self::SAFETY_TAG_COUNT);
    }
}
