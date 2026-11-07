<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

/** Optional wire-format metadata for a provider's request payload projection. */
interface DescribesProjectedPayload
{
    /**
     * Return the projected wire-format identifier without projecting or sending.
     * Built-in identifiers are anthropic-messages, openai-responses, and
     * openai-chat-completions. Null means the format is undisclosed.
     * Consumers must treat identifiers they do not recognise as unknown.
     *
     * A decorator preserving the projected format should forward this method
     * alongside ProjectsRequestPayload::projectPayload(). This declaration alone
     * does not imply that a provider exposes a projection.
     */
    public function projectedPayloadFormat(): ?string;
}
