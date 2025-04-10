<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Maps;

use Prism\Prism\Providers\Anthropic\ValueObjects\Citation as AnthropicCitation;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations as AnthropicMessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class CitationMap
{
    /**
     * Maps Anthropic MessagePartWithCitations to standardized MessagePartWithCitations
     */
    public static function mapMessagePart(AnthropicMessagePartWithCitations $anthropicPart): MessagePartWithCitations
    {
        return new MessagePartWithCitations(
            text: $anthropicPart->text,
            citations: array_map(
                fn (AnthropicCitation $citation): Citation => self::mapCitation($citation),
                $anthropicPart->citations
            )
        );
    }

    /**
     * Maps Anthropic Citation to standardized Citation
     */
    public static function mapCitation(AnthropicCitation $citation): Citation
    {
        return new Citation(
            text: $citation->citedText,
            startPosition: $citation->startIndex,
            endPosition: $citation->endIndex,
            sourceIndex: $citation->documentIndex,
            sourceTitle: $citation->documentTitle,
            type: $citation->type
        );
    }

    /**
     * @param  array<string, mixed>  $contentBlock
     */
    public static function fromContentBlock(array $contentBlock): MessagePartWithCitations
    {
        $anthropicPart = AnthropicMessagePartWithCitations::fromContentBlock($contentBlock);

        return self::mapMessagePart($anthropicPart);
    }
}
