<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI as ServicesAI;
use Acms\Plugins\AI\Services\AI\EntryAiSettings;

/**
 * ACMS_POST_AI_Title
 */
class Title extends ACMS_POST
{
    use AIPostTrait;

    public function post(): mixed
    {
        $this->initAiConfig();

        $article = $this->Post->get('article');

        $serviceAI = new ServicesAI();
        $config = $serviceAI->getConfig();
        $settings = new EntryAiSettings($config);

        // 「有効」設定はフロントの表示制御に加えて、直接 POST への防御として二重に検査する
        if (!$settings->titleEnabled()) {
            return $this->errorResponse('タイトル生成は管理画面で有効化されていません。');
        }

        $customPrompt = $settings->titlePrompt();

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
