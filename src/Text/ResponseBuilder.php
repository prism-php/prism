<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Illuminate\Support\Collection;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Usage;

readonly class ResponseBuilder
{
    /** @var Collection<int, Step> */
    public Collection $steps;

    public function __construct()
    {
        $this->steps = new Collection;
    }

    public function addStep(Step $step): self
    {
        $this->steps->push($step);

        return $this;
    }

    public function toResponse(): Response
    {
        /** @var Step $finalStep */
        $finalStep = $this->steps->last();

        // Build messages collection: input messages + final assistant message
        $messages = collect($finalStep->messages);

        // Include provider_tool_calls in additionalContent if present
        $additionalContent = $finalStep->additionalContent;
        if ($finalStep->providerToolCalls !== []) {
            $additionalContent['provider_tool_calls'] = $finalStep->providerToolCalls;
        }

        $messages->push(new AssistantMessage(
            content: $finalStep->text,
            toolCalls: $finalStep->toolCalls,
            additionalContent: $additionalContent,
        ));

        return new Response(
            steps: $this->steps,
            text: $finalStep->text,
            finishReason: $finalStep->finishReason,
            toolCalls: $finalStep->toolCalls,
            toolResults: $finalStep->toolResults,
            usage: $this->calculateTotalUsage(),
            meta: $finalStep->meta,
            messages: $messages,
            additionalContent: $finalStep->additionalContent,
            raw: $finalStep->raw,
        );
    }

    protected function calculateTotalUsage(): Usage
    {
        return new Usage(
            promptTokens: $this
                ->steps
                ->sum(fn (Step $result): int => $result->usage->promptTokens),
            completionTokens: $this
                ->steps
                ->sum(fn (Step $result): int => $result->usage->completionTokens),
            cacheWriteInputTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->cacheWriteInputTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->cacheWriteInputTokens ?? 0)
                : null,
            cacheReadInputTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->cacheReadInputTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->cacheReadInputTokens ?? 0)
                : null,
            thoughtTokens: $this->steps->contains(fn (Step $result): bool => $result->usage->thoughtTokens !== null)
                ? $this->steps->sum(fn (Step $result): int => $result->usage->thoughtTokens ?? 0)
                : null,
        );
    }
}
