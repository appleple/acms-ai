<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Vision;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\GenerationResult;
use Acms\Plugins\AI\Services\AI\Vision\MediaFieldGenerator;
use Acms\Plugins\AI\Tests\Support\FakeAiProvider;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * メディア画像からのフィールド生成ロジック（対象の絞り込み・プロンプト組み立て・
 * 構造化出力スキーマ・応答の正規化）を固定する。プロバイダは {@see FakeAiProvider} で差し替える。
 */
final class MediaFieldGeneratorTest extends TestCase
{
    private const IMAGE = 'data:image/jpeg;base64,aW1n';

    /**
     * @param array<string, string> $values
     */
    private function config(array $values = []): Field
    {
        $field = new Field();
        foreach ($values as $key => $value) {
            $field->set($key, $value);
        }
        return $field;
    }

    /**
     * 全項目を有効化した config を返す。
     *
     * @param array<string, string> $extra
     */
    private function allEnabledConfig(array $extra = []): Field
    {
        $values = ['ai_vision_valid' => 'on'];
        foreach (MediaFieldGenerator::VALID_CONFIG_KEYS as $key) {
            $values[$key] = 'on';
        }
        return $this->config($values + $extra);
    }

    #[Test]
    #[TestDox('enabledTargets は許可リスト外と管理者が無効化した項目を除外する')]
    public function enabledTargetsFiltersDisallowedAndDisabled(): void
    {
        $config = $this->config([
            'ai_vision_valid_alt' => 'on',
            'ai_vision_valid_tags' => 'on',
            // caption / memo / filename は無効（未設定）
        ]);
        $generator = new MediaFieldGenerator();

        $targets = $generator->enabledTargets($config, ['alt', 'caption', 'tags', 'evil_field', ' alt ']);

        self::assertSame(['alt', 'tags'], $targets);
    }

    #[Test]
    #[TestDox('generate は画像とプロンプトを 1 リクエストへまとめ、要求キーのスキーマで構造化出力を要求する')]
    public function generateBuildsVisionRequestWithSchema(): void
    {
        $provider = new FakeAiProvider(new GenerationResult('{"alt":"猫の写真","tags":["猫","動物"]}'));
        $generator = new MediaFieldGenerator();

        $fields = $generator->generate($provider, $this->allEnabledConfig(), 'test-model', self::IMAGE, ['alt', 'tags']);

        $request = $provider->lastRequest;
        self::assertNotNull($request);
        self::assertSame('test-model', $request->model);

        // system 指示は既定文（config 未設定時）。
        self::assertSame(MediaFieldGenerator::DEFAULT_SYSTEM_PROMPT, $request->instructions);

        // 1 メッセージにテキスト（項目別指示）と画像（data URL）が入る。
        self::assertCount(1, $request->messages);
        $parts = $request->messages[0]->parts;
        self::assertCount(2, $parts);
        self::assertSame(ContentPart::TYPE_TEXT, $parts[0]->type);
        self::assertStringContainsString('- "alt": ', $parts[0]->value);
        self::assertStringContainsString('- "tags": ', $parts[0]->value);
        self::assertSame(ContentPart::TYPE_IMAGE, $parts[1]->type);
        self::assertSame(self::IMAGE, $parts[1]->value);

        // スキーマは要求キーのみ。tags は文字列配列。
        self::assertNotNull($request->outputSchema);
        self::assertSame(['alt', 'tags'], array_keys($request->outputSchema['properties']));
        self::assertSame('array', $request->outputSchema['properties']['tags']['type']);
        self::assertSame(['alt', 'tags'], $request->outputSchema['required']);

        self::assertSame(['alt' => '猫の写真', 'tags' => ['猫', '動物']], $fields);
    }

    #[Test]
    #[TestDox('config のプロンプトが設定されていれば既定文より優先する')]
    public function configPromptsOverrideDefaults(): void
    {
        $provider = new FakeAiProvider(new GenerationResult('{"alt":"a"}'));
        $config = $this->allEnabledConfig([
            'ai_vision_system_prompt' => 'カスタムシステム',
            'ai_vision_prompt_alt' => 'カスタム指示',
        ]);

        (new MediaFieldGenerator())->generate($provider, $config, 'm', self::IMAGE, ['alt']);

        $request = $provider->lastRequest;
        self::assertNotNull($request);
        self::assertSame('カスタムシステム', $request->instructions);
        self::assertStringContainsString('- "alt": カスタム指示', $request->messages[0]->parts[0]->value);
    }

    #[Test]
    #[TestDox('file_name はスラッグ化、alt/caption は引用符・改行を除去、tags は重複除去される')]
    public function normalizesEachFieldType(): void
    {
        $raw = json_encode([
            'file_name' => 'Business Woman Office.JPG',
            'alt' => "「オフィスで働く\n女性」",
            'caption' => '  "会議室の様子"  ',
            'memo' => "  1行目\n2行目  ",
            'tags' => ['猫', ' 猫 ', '', 'ビジネス,仕事', 123],
        ], JSON_UNESCAPED_UNICODE);
        $provider = new FakeAiProvider(new GenerationResult($raw === false ? '{}' : $raw));
        $generator = new MediaFieldGenerator();

        $fields = $generator->generate(
            $provider,
            $this->allEnabledConfig(),
            'm',
            self::IMAGE,
            ['file_name', 'alt', 'caption', 'memo', 'tags']
        );

        self::assertSame('business-woman-office', $fields['file_name']);
        self::assertSame('オフィスで働く 女性', $fields['alt']);
        self::assertSame('会議室の様子', $fields['caption']);
        self::assertSame("1行目\n2行目", $fields['memo']);
        self::assertSame(['猫', 'ビジネス 仕事'], $fields['tags']);
    }

    #[Test]
    #[TestDox('コードフェンスや前後の説明文が付いた応答からも JSON を救出する')]
    public function salvagesJsonFromFencedResponse(): void
    {
        $provider = new FakeAiProvider(new GenerationResult(
            "以下が結果です。\n```json\n{\"alt\":\"救出成功\"}\n```"
        ));

        $fields = (new MediaFieldGenerator())->generate($provider, $this->allEnabledConfig(), 'm', self::IMAGE, ['alt']);

        self::assertSame(['alt' => '救出成功'], $fields);
    }

    #[Test]
    #[TestDox('生成失敗（text 空）はプロバイダのエラーメッセージ付きで例外にする')]
    public function throwsWithProviderErrorMessage(): void
    {
        $provider = new FakeAiProvider(new GenerationResult(null, errorMessage: 'クレジット残高が不足しています。'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('クレジット残高');

        (new MediaFieldGenerator())->generate($provider, $this->allEnabledConfig(), 'm', self::IMAGE, ['alt']);
    }

    #[Test]
    #[TestDox('JSON でない応答・有効値ゼロの応答は例外にする')]
    public function throwsOnUnparsableOrEmptyResults(): void
    {
        $generator = new MediaFieldGenerator();
        $config = $this->allEnabledConfig();

        $notJson = new FakeAiProvider(new GenerationResult('ただの文章です'));
        try {
            $generator->generate($notJson, $config, 'm', self::IMAGE, ['alt']);
            self::fail('例外が発生しませんでした');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('解析に失敗', $e->getMessage());
        }

        // 要求外キーのみ・空値のみ → 有効な値なし
        $empty = new FakeAiProvider(new GenerationResult('{"unrelated":"x","alt":""}'));
        try {
            $generator->generate($empty, $config, 'm', self::IMAGE, ['alt']);
            self::fail('例外が発生しませんでした');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('有効な値', $e->getMessage());
        }
    }
}
