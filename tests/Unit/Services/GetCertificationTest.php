<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services;

use Acms\Plugins\AI\Services\AI;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * コンフィグ Field から認証情報（組織 ID・プロジェクト ID・API キー・モデル）を取り出す
 * AI::getCertification の写像を固定する。
 *
 * Field を注入して与えることで DB / Config 読み込みを介さず、保存値 → 認証情報配列の変換だけを
 * 決定的に検証できる。
 */
final class GetCertificationTest extends TestCase
{
    /**
     * @param array<string, string> $values acms_config のキー => 値
     */
    private function fieldWith(array $values): Field
    {
        $field = new Field();
        foreach ($values as $key => $value) {
            $field->set($key, $value);
        }
        return $field;
    }

    #[Test]
    #[TestDox('設定済みのキーをそれぞれ対応する認証情報キーへ写像する')]
    public function mapsConfiguredValues(): void
    {
        $field = $this->fieldWith([
            'ai_organization_id' => 'org-123',
            'ai_project_id' => 'proj-456',
            'ai_api_key' => 'sk-secret',
            'ai_model' => 'gpt-5.4-mini',
        ]);

        $cert = (new AI())->getCertification($field);

        $this->assertSame('org-123', $cert['ai_organization_id']);
        $this->assertSame('proj-456', $cert['ai_project_id']);
        $this->assertSame('sk-secret', $cert['ai_api_key']);
        $this->assertSame('gpt-5.4-mini', $cert['ai_model']);
    }

    #[Test]
    #[TestDox('返却配列は認証情報の 4 キーだけを持つ')]
    public function returnsExactlyTheFourKeys(): void
    {
        $cert = (new AI())->getCertification($this->fieldWith([]));

        $this->assertSame(
            ['ai_organization_id', 'ai_project_id', 'ai_api_key', 'ai_model'],
            array_keys($cert)
        );
    }
}
