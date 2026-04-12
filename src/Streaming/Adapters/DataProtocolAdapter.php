<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming\Adapters;

use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\ProviderToolEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DataProtocolAdapter
{
    /**
     * Track tool call IDs for provider tool events.
     * tool-output-available may be sent without a prior tool-input-available (e.g. Dispatched in subsequent request for tools needing approval).
     *
     * @var array<string, bool>
     */
    protected array $startedToolCallIds = [];

    /**
     * @param  string|null  $responseMessageId  When set, the start event echoes this ID instead of the
     *                                          provider-generated one. The Vercel AI SDK client sends a
     *                                          messageId when it needs the server to continue an existing
     *                                          assistant message — for example after a client-executed tool
     *                                          result (addToolResult), a tool output (addToolOutput), or any
     *                                          automatic resubmission triggered by sendAutomaticallyWhen.
     *                                          Without this, the UI would create a new message bubble instead
     *                                          of appending to the current one.
     */
    public function __construct(
        protected ?string $responseMessageId = null,
    ) {}

    /**
     * @param  callable(PendingRequest, Collection<int, StreamEvent>): void|null  $callback
     */
    public function __invoke(Generator $events, ?PendingRequest $pendingRequest = null, ?callable $callback = null): StreamedResponse
    {
        return response()->stream(function () use ($events, $pendingRequest, $callback): void {
            $this->startedToolCallIds = [];

            /** @var Collection<int, StreamEvent> $collectedEvents */
            $collectedEvents = new Collection;

            try {
                foreach ($events as $event) {
                    $collectedEvents->push($event);

                    if (connection_aborted() !== 0) {
                        break;
                    }

                    $data = $this->handleEventConversion($event);

                    // Skip events that return null
                    if ($data === null) {
                        continue;
                    }

                    echo "data: {$data}\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } catch (Throwable $e) {
                $errorEvent = new ErrorEvent(
                    id: EventID::generate(),
                    timestamp: time(),
                    errorType: $e::class,
                    message: $e->getMessage(),
                    recoverable: false,
                    metadata: [
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );

                $collectedEvents->push($errorEvent);
                $this->outputError($e->getMessage());
            }

            echo "data: [DONE]\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            if ($callback !== null && $pendingRequest instanceof PendingRequest) {
                $callback($pendingRequest, $collectedEvents);
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }

    protected function outputError(string $message): void
    {
        $errorData = json_encode([
            'type' => 'error',
            'errorText' => $message,
        ]);

        echo "data: {$errorData}\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    protected function handleEventConversion(StreamEvent $event): ?string
    {
        $data = match ($event::class) {
            StreamStartEvent::class => $this->handleStreamStart($event),
            StepStartEvent::class => $this->handleStepStart($event),
            TextStartEvent::class => $this->handleTextStart($event),
            TextDeltaEvent::class => $this->handleTextDelta($event),
            TextCompleteEvent::class => $this->handleTextComplete($event),
            ThinkingStartEvent::class => $this->handleThinkingStart($event),
            ThinkingEvent::class => $this->handleThinkingDelta($event),
            ThinkingCompleteEvent::class => $this->handleThinkingComplete($event),
            ToolCallEvent::class => $this->handleToolCall($event),
            ToolApprovalRequestEvent::class => $this->handleToolApprovalRequest($event),
            ToolResultEvent::class => $this->handleToolResult($event),
            ArtifactEvent::class => $this->handleArtifact($event),
            ProviderToolEvent::class => $this->handleProviderTool($event),
            StepFinishEvent::class => $this->handleStepFinish($event),
            StreamEndEvent::class => $this->handleStreamEnd($event),
            ErrorEvent::class => $this->handleError($event),
            default => $this->handleDefault($event),
        };

        // Skip events that return null (e.g., intermediate provider tool statuses)
        if ($data === null) {
            return null;
        }

        $encoded = json_encode($data);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode event data as JSON');
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleStreamStart(StreamStartEvent $event): array
    {
        return [
            'type' => 'start',
            'messageId' => $this->responseMessageId ?? $event->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleTextStart(TextStartEvent $event): array
    {
        return [
            'type' => 'text-start',
            'id' => $event->messageId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleTextDelta(TextDeltaEvent $event): array
    {
        return [
            'type' => 'text-delta',
            'id' => $event->messageId,
            'delta' => $event->delta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleTextComplete(TextCompleteEvent $event): array
    {
        return [
            'type' => 'text-end',
            'id' => $event->messageId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleThinkingStart(ThinkingStartEvent $event): array
    {
        return [
            'type' => 'reasoning-start',
            'id' => $event->reasoningId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleThinkingDelta(ThinkingEvent $event): array
    {
        return [
            'type' => 'reasoning-delta',
            'id' => $event->reasoningId,
            'delta' => $event->delta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleThinkingComplete(ThinkingCompleteEvent $event): array
    {
        return [
            'type' => 'reasoning-end',
            'id' => $event->reasoningId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleToolCall(ToolCallEvent $event): array
    {
        $this->startedToolCallIds[$event->toolCall->id] = true;

        return [
            'type' => 'tool-input-available',
            'toolCallId' => $event->toolCall->id,
            'toolName' => $event->toolCall->name,
            'input' => $event->toolCall->arguments(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleToolApprovalRequest(ToolApprovalRequestEvent $event): array
    {
        $this->startedToolCallIds[$event->toolCall->id] = true;

        return [
            'type' => 'tool-approval-request',
            'approvalId' => $event->approvalId,
            'toolCallId' => $event->toolCall->id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function handleToolResult(ToolResultEvent $event): ?array
    {
        $toolCallId = $event->toolResult->toolCallId;

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $toolCallId,
            'output' => $event->toolResult->result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleArtifact(ArtifactEvent $event): array
    {
        return [
            'type' => 'data-artifact',
            'data' => [
                'toolCallId' => $event->toolCallId,
                'toolName' => $event->toolName,
                'artifact' => [
                    'id' => $event->artifact->id,
                    'mimeType' => $event->artifact->mimeType,
                    'data' => $event->artifact->data,
                    'metadata' => $event->artifact->metadata,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function handleProviderTool(ProviderToolEvent $event): ?array
    {
        return match ($event->status) {
            'started' => $this->handleProviderToolStarted($event),
            'completed' => $this->handleProviderToolCompleted($event),
            'result_received' => $this->handleProviderToolResult($event),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleProviderToolStarted(ProviderToolEvent $event): array
    {
        $this->startedToolCallIds[$event->itemId] = true;

        return [
            'type' => 'tool-input-available',
            'toolCallId' => $event->itemId,
            'toolName' => $event->toolType,
            'input' => $event->data['input'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleProviderToolCompleted(ProviderToolEvent $event): array
    {
        $inputJson = $event->data['input'] ?? '';
        $input = is_string($inputJson) && $inputJson !== '' ? json_decode($inputJson, true) : [];

        return [
            'type' => 'tool-input-available',
            'toolCallId' => $event->itemId,
            'toolName' => $event->toolType,
            'input' => $input ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function handleProviderToolResult(ProviderToolEvent $event): ?array
    {
        if (! isset($this->startedToolCallIds[$event->itemId])) {
            return null;
        }

        return [
            'type' => 'tool-output-available',
            'toolCallId' => $event->itemId,
            'output' => json_encode($event->data['content'] ?? $event->data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleStreamEnd(StreamEndEvent $event): array
    {
        $messageMetadata = [
            'finishReason' => $event->finishReason->value,
        ];

        if ($event->usage instanceof Usage) {
            $messageMetadata['usage'] = [
                'promptTokens' => $event->usage->promptTokens,
                'completionTokens' => $event->usage->completionTokens,
            ];
        }

        return [
            'type' => 'finish',
            'messageMetadata' => $messageMetadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleError(ErrorEvent $event): array
    {
        return [
            'type' => 'error',
            'errorText' => $event->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleDefault(StreamEvent $event): array
    {
        return [
            'type' => 'data-'.$event->type()->value,
            'data' => $event->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleStepStart(StepStartEvent $event): array
    {
        return [
            'type' => 'start-step',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function handleStepFinish(StepFinishEvent $event): array
    {
        return [
            'type' => 'finish-step',
        ];
    }
}
