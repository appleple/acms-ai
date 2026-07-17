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

        $article = $this->Post->get('article');

        $serviceAI = new ServicesAI();
        $config = $serviceAI->getConfig();

        $titleValid = $config->get('ai_title_valid');
        $customPrompt = $titleValid !== ''
            ? $config->get('ai_title_prompt')
            : "- Please give 5 suggestions.\n- Please answer in Japanese.";

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
