<?php

namespace Acms\Plugins\AI\POST\AI;

use ACMS_POST;
use Acms\Plugins\AI\POST\AIPostTrait;
use Acms\Plugins\AI\Services\AI as ServiceAI;
use Acms\Plugins\AI\Services\AI\Contracts\ContentPart;
use Acms\Plugins\AI\Services\AI\Contracts\Message;

/**
 * ACMS_POST_AI_Tag
 */
class Tag extends ACMS_POST
{
    use AIPostTrait;

    /**
     * 既存タグの一覧をユーザー／アシスタントの往復として先頭に差し込み、表記ゆれを抑えたタグ生成を促す。
     *
     * @return list<Message>
     */
    protected function additionalMessages(): array
    {
        $tagNameAll = ServiceAI::getTagNameAll();
        $tagStr = implode(", ", $tagNameAll);

        if ($tagStr === '') {
            return [];
        }

        return [
            Message::user(ContentPart::text(
                "This is a list of existing tags. "
                . "When generating tags, please use the existing tag list notation"
                . " for tags with duplicate meanings.\n\n"
                . "list of existing tags: \"\"\"\n$tagStr\n\"\"\""
            )),
            Message::assistant(ContentPart::text(
                "I got it. Please give me some prompts regarding tag generation."
            )),
        ];
    }

    public function post(): mixed
    {
        $this->initAiConfig();

        $article = $this->Post->get('article');
        $addPrompt = $this->Post->get('addPrompt');
        $alreadyGeneratedTagsRaw = $this->Post->get('alreadyGeneratedTags');
        $alreadyGeneratedTags = $alreadyGeneratedTagsRaw !== ''
            ? json_decode($alreadyGeneratedTagsRaw, true)
            : [];

        $serviceAI = new ServiceAI();
        $config = $serviceAI->getConfig();

        $tagValid = $config->get('ai_tag_valid');
        $customPrompt = $tagValid !== ''
            ? $config->get('ai_tag_prompt')
            : 'Please answer in Japanese.';

        $content = "Consider the tags for this article.\n\ncondition:\n{$customPrompt}\n"
            . "Please generate the linked tag without including the set tag.\n\n"
            . "article: \"\"\"\n{$article}\n\"\"\"";

        if ($addPrompt !== '') {
            $content .= "\n\nTags set: \"\"\"\n{$addPrompt}\n\"\"\"";
        }

        if (is_array($alreadyGeneratedTags) && $alreadyGeneratedTags !== []) {
            $tagList = implode(', ', $alreadyGeneratedTags);
            $content .= "\n\nAlready generated tags (do not include these in the new suggestions): \"\"\"\n"
                . "{$tagList}\n\"\"\"";
        }

        $promptMessages = [['role' => 'user', 'content' => $content]];

        return $this->executeAiRequest(
            "You are a system that returns tag suggestions as a JSON array. "
            . "Each element must have a \"content\" key with the tag name as value. "
            . "Do not include any tags that are listed under \"Already generated tags\" or \"Tags set\" in the prompt. "
            . "Every suggestion must be unique and not duplicate any previously generated tag.",
            'tag_suggestions',
            $promptMessages
        );
    }
}
