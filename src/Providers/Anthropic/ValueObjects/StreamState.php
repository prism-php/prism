<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use Illuminate\Support\Arr;

class StreamState
{
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<int, MessagePartWithCitations>  $citations
     * @param  array<string, int>  $usage
     * @param  array<string, mixed>|null  $tempCitation
     */
    public function __construct(
        protected string $model = '',
        protected string $requestId = '',
        protected string $text = '',
        protected array $toolCalls = [],
        protected string $thinking = '',
        protected string $thinkingSignature = '',
        protected array $citations = [],
        protected string $stopReason = '',
        protected ?array $usage = [],
        protected ?string $tempContentBlockType = null,
        protected ?int $tempContentBlockIndex = null,
        protected ?array $tempCitation = null,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function appendText(string $text): self
    {
        $this->text .= $text;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    public function setToolCalls(array $toolCalls): self
    {
        $this->toolCalls = $toolCalls;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $toolCall
     */
    public function addToolCall(int $index, array $toolCall): self
    {
        $this->toolCalls[$index] = $toolCall;

        return $this;
    }

    public function appendToolCallInput(int $index, string $input): self
    {
        if (isset($this->toolCalls[$index])) {
            $this->toolCalls[$index]['input'] .= $input;
        }

        return $this;
    }

    public function thinking(): string
    {
        return $this->thinking;
    }

    public function appendThinking(string $thinking): self
    {
        $this->thinking .= $thinking;

        return $this;
    }

    public function thinkingSignature(): string
    {
        return $this->thinkingSignature;
    }

    public function appendThinkingSignature(string $signature): self
    {
        $this->thinkingSignature .= $signature;

        return $this;
    }

    /**
     * @return array<int, MessagePartWithCitations>
     */
    public function citations(): array
    {
        return $this->citations;
    }

    public function addCitation(MessagePartWithCitations $citation): self
    {
        $this->citations[] = $citation;

        return $this;
    }

    public function stopReason(): string
    {
        return $this->stopReason;
    }

    public function setStopReason(string $reason): self
    {
        $this->stopReason = $reason;

        return $this;
    }

    /**
     * @return array<string, int>|null
     */
    public function usage(): ?array
    {
        return $this->usage;
    }

    /**
     * @param  array<string, int>  $usage
     */
    public function setUsage(array $usage): self
    {
        if ($this->usage === []) {
            $this->usage = $usage;
        } else {
            foreach (Arr::dot($usage) as $key => $value) {
                Arr::set($this->usage, $key, Arr::get($this->usage, $key, 0) + $value);
            }
        }

        return $this;
    }

    public function tempContentBlockType(): ?string
    {
        return $this->tempContentBlockType;
    }

    public function setTempContentBlockType(?string $type): self
    {
        $this->tempContentBlockType = $type;

        return $this;
    }

    public function tempContentBlockIndex(): ?int
    {
        return $this->tempContentBlockIndex;
    }

    public function setTempContentBlockIndex(?int $index): self
    {
        $this->tempContentBlockIndex = $index;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function tempCitation(): ?array
    {
        return $this->tempCitation;
    }

    /**
     * @param  array<string, mixed>|null  $citation
     */
    public function setTempCitation(?array $citation): self
    {
        $this->tempCitation = $citation;

        return $this;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function isToolUseFinish(): bool
    {
        return $this->stopReason === 'tool_use' && $this->hasToolCalls();
    }

    public function reset(): self
    {
        $this->model = '';
        $this->requestId = '';
        $this->text = '';
        $this->toolCalls = [];
        $this->thinking = '';
        $this->thinkingSignature = '';
        $this->citations = [];
        $this->stopReason = '';
        $this->usage = [];
        $this->tempContentBlockType = null;
        $this->tempContentBlockIndex = null;
        $this->tempCitation = null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAdditionalContent(): array
    {
        $additionalContent = [];

        if ($this->thinking !== '') {
            $additionalContent['thinking'] = $this->thinking;

            if ($this->thinkingSignature !== '') {
                $additionalContent['thinking_signature'] = $this->thinkingSignature;
            }
        }

        if ($this->citations !== []) {
            $additionalContent['messagePartsWithCitations'] = $this->citations;
        }

        return $additionalContent;
    }

    public function resetContentBlock(): self
    {
        $this->tempContentBlockType = null;
        $this->tempContentBlockIndex = null;
        $this->tempCitation = null;

        return $this;
    }
}
