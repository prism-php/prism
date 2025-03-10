<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\ValueObjects\Usage;

readonly class ResponseBuilder
{
    /** @var Collection<int, Step> */
    public Collection $steps;

    /** @var Collection<int, Message> */
    public Collection $responseMessages;

    public function __construct()
    {
        $this->steps = new Collection;
        $this->responseMessages = new Collection;
    }

    public function addResponseMessage(Message $message): self
    {
        $this->responseMessages->push($message);

        return $this;
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

        return new Response(
            steps: $this->steps,
            responseMessages: $this->responseMessages,
            text: $finalStep->text,
            structured: $finalStep->finishReason === FinishReason::Stop
                ? $this->decodeObject($finalStep->text)
                : [],
            finishReason: $finalStep->finishReason,
            usage: $this->calculateTotalUsage(),
            meta: $finalStep->meta,
            additionalContent: $finalStep->additionalContent,
        );
    }

    /**
     * @return array<mixed>
     */
    protected function decodeObject(string $responseText): array
    {
        try {
            return json_decode($responseText, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PrismStructuredDecodingException::make($responseText);
        }
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
        );
    }
}
