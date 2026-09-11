<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\Tests\Unit\Services\Vision;

use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Vision\MediaFieldGenerator;
use Acms\Plugins\AI\Tests\Support\MediaFieldFakeProvider;
use Acms\TestingFramework\TestCase;
use Field;
use PHPUnit\Framework\Attributes\Test;

final class MediaFieldGeneratorTest extends TestCase
{
    #[Test]
    public function itFiltersTargetsByAllowListAndConfiguration(): void
    {
        $config = new Field();
        $config->set('ai_vision_enabled_alt', 'on');
        $config->set('ai_vision_enabled_tags', 'off');

        self::assertSame(
            ['alt'],
            (new MediaFieldGenerator())->enabledTargets($config, ['alt', 'tags', 'unknown', 'alt'])
        );
    }

    #[Test]
    public function itBuildsARequiredSchemaAndNormalizesAllRequestedFields(): void
    {
        $generator = new MediaFieldGenerator();
        $config = new Field();
        $image = ContentPart::imageData('image/png', 'YWJj');
        $request = $generator->buildRequest($config, 'vision-model', $image, ['file_name', 'alt', 'tags']);
        $provider = new MediaFieldFakeProvider(json_encode([
            'file_name' => ' Cat Photo.PNG ',
            'alt' => "「猫の写真」\n",
            'tags' => [' 猫 ', '動物', '猫'],
        ], JSON_UNESCAPED_UNICODE));

        self::assertSame(['file_name', 'alt', 'tags'], $request->outputSchema['required']);
        self::assertSame([
            'file_name' => 'cat-photo',
            'alt' => '猫の写真',
            'tags' => ['猫', '動物'],
        ], $generator->generate($provider, $request, ['file_name', 'alt', 'tags']));
    }

    #[Test]
    public function itRejectsMissingOrUnexpectedFieldsInsteadOfPartiallyApplyingThem(): void
    {
        $generator = new MediaFieldGenerator();
        $request = $generator->buildRequest(
            new Field(),
            'vision-model',
            ContentPart::imageData('image/jpeg', 'YWJj'),
            ['alt']
        );

        $this->expectException(\RuntimeException::class);
        $generator->generate(new MediaFieldFakeProvider('{"caption":"unexpected"}'), $request, ['alt']);
    }
}
