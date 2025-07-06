<?php

declare(strict_types=1);

namespace Prism\Prism\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolResult;

abstract class TextHandler
{
    protected Response $httpResponse;

    protected TextResponse $tempResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(protected PendingRequest $client, protected TextRequest $request)
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function handle(): TextResponse
    {
        $this->sendRequest();

        $this->prepareTempResponse();

        $this->validateResponse();

        $responseMessage = new AssistantMessage(
            $this->tempResponse->text,
            $this->tempResponse->toolCalls,
            $this->tempResponse->additionalContent,
        );

        $this->responseBuilder->addResponseMessage($responseMessage);

        $this->request->addMessage($responseMessage);

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls(),
            FinishReason::Stop, FinishReason::Length => $this->handleStop(),
            default => throw new PrismException($this->request->provider().': unknown finish reason'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    abstract public static function buildHttpRequestPayload(TextRequest $request): array;

    protected function handleStop(): TextResponse
    {
        $this->addStep();

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(): bool
    {
        return $this->responseBuilder->steps->count() < $this->request->maxSteps();
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            toolCalls: $this->tempResponse->toolCalls,
            toolResults: $toolResults,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $this->request->messages(),
            systemPrompts: $this->request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
        ));
    }

    protected function validateResponse(): void {}

    abstract protected function handleToolCalls(): TextResponse;

    abstract protected function prepareTempResponse(): void;

    abstract protected function sendRequest(): void;
}
