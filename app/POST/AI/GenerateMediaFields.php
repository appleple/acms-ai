<?php

declare(strict_types=1);

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI\Contracts\Capability;
use Acms\Plugins\AI\Services\AI\Vision\MediaFieldGenerator;
use Acms\Plugins\AI\Services\AI\Vision\MediaImageLoader;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Response;

/** メディア画像から編集フォームの候補値を1回の画像解析で生成する。 */
final class GenerateMediaFields extends ACMS_POST
{
    use AIPostTrait;

    public function post(): mixed
    {
        $config = $this->prepareAiRequest();
        if ($config->get('ai_vision_enabled') !== 'on') {
            $this->errorResponse('メディアAI生成は管理画面で有効化されていません。', 403, [
                'reason' => 'feature_disabled',
            ]);
        }

        $generator = new MediaFieldGenerator();
        $targets = $generator->enabledTargets($config, explode(',', $this->Post->get('targets')));
        if ($targets === []) {
            $this->errorResponse('生成する項目を選択してください。', 400, ['reason' => 'no_enabled_targets']);
        }
        if (
            $this->provider === null
            || !$this->provider->isConfigured()
            || !$this->provider->supports(Capability::VisionInput)
            || !$this->provider->supports(Capability::StructuredOutput)
        ) {
            $this->errorResponse('選択中のAIプロバイダは画像解析に対応していません。', 400, [
                'reason' => 'unsupported_provider',
                'provider' => $this->provider?->id(),
            ]);
        }

        $model = trim($config->get('ai_vision_model'));
        if ($model === '') {
            $model = $this->model;
        }
        if ($model === '') {
            $this->errorResponse('画像解析に使用するモデルを設定してください。', 400, ['reason' => 'missing_model']);
        }

        try {
            $image = (new MediaImageLoader())->load((int) $this->Post->get('mid'));
            $request = $generator->buildRequest($config, $model, $image, $targets);
            $this->assertGenerationInputWithinLimit($request);
            $fields = $generator->generate($this->provider, $request, $targets);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->errorResponse($e->getMessage(), 400, [
                'reason' => 'media_generation_failed',
                'mediaId' => (int) $this->Post->get('mid'),
                'targets' => $targets,
            ]);
        } catch (\Throwable $e) {
            Logger::error('【AI plugin】メディアAI生成で予期しないエラーが発生しました', [
                'exception' => $e::class,
            ]);
            $this->errorResponse('画像解析に失敗しました。', 500, ['reason' => 'unexpected_error']);
        }

        Response::json(['fields' => $fields]);
    }
}
