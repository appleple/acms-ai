<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI as ServicesAI;

/**
 * ACMS_POST_AI_Title
 */
class Title extends ACMS_POST
{
    use AIPostTrait;

    public function post(): mixed
    {
        $this->initAiConfig();

        if (($denied = $this->denyUnlessContribution()) !== null) {
            return $denied;
        }

        $article = $this->Post->get('article');

        $serviceAI = new ServicesAI();
        $config = $serviceAI->getConfig();

        // 「有効」設定はフロントの表示制御に加えて、直接 POST への防御として二重に検査する
        if ($config->get('ai_title_valid') === '') {
            return $this->errorResponse('タイトル生成は管理画面で有効化されていません。');
        }

        // 保存済みプロンプト（既定値は config.system.yaml が供給。空なら内蔵既定へフォールバック）
        $customPrompt = trim($config->get('ai_title_prompt'));
        if ($customPrompt === '') {
            $customPrompt = "- Please give 5 suggestions.\n- Please answer in Japanese.";
        }

        $promptMessages = [
            [
                'role' => 'user',
                'content' => "Think about the title for this article.\n\ncondition:\n{$customPrompt}\n\n"
                    . "article: \"\"\"\n{$article}\n\"\"\""
            ]
        ];

        return $this->executeAiRequest(
            "You are a system that returns title suggestions as a JSON array. "
            . "Each element must have a \"content\" key with the title as value.",
            'title_suggestions',
            $promptMessages
        );
    }
}
