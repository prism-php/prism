<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;

final class ClientExecutedInvokableTool extends Tool
{
    public int $runs = 0;

    public function __construct()
    {
        $this->as('hand_back')->for('Hand the call back')->withStringParameter('target', 'Target');
    }

    public function __invoke(string $target): string
    {
        $this->runs++;

        return 'ran';
    }
}

it('does not run a class-based tool once it is client-executed', function (): void {
    $tool = (new ClientExecutedInvokableTool)->clientExecuted();

    $result = $tool->handle(target: 'x');

    expect($tool->runs)->toBe(0)
        ->and($result)->toBeInstanceOf(ToolError::class);
});

it('raises for a client-executed tool when error handling is off', function (): void {
    $tool = (new ClientExecutedInvokableTool)->clientExecuted()->withoutErrorHandling();

    expect(fn (): mixed => $tool->handle(target: 'x'))->toThrow(PrismException::class, 'client-executed')
        ->and($tool->runs)->toBe(0);
});

it('does not run a client-executed tool when an approved call is replayed', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([
        'id' => 'resp_1', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => [['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'done', 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ], 200)]);
    config()->set('prism.providers.openai.api_key', 'not-a-key');

    $tool = (new ClientExecutedInvokableTool)->requiresApproval(true)->clientExecuted();

    Prism::text()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withTools([$tool])
        ->withMessages([
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('call_1', 'hand_back', ['target' => 'x'])], [], [new ToolApprovalRequest('apr_1', 'call_1')]),
            new ToolResultMessage([], [new ToolApprovalResponse('apr_1', true)]),
        ])
        ->asText();

    Http::assertSentCount(1);
    expect($tool->runs)->toBe(0);
});

it('runs the tool again once a handler is given back', function (): void {
    $tool = (new ClientExecutedInvokableTool)->clientExecuted()->using(fn (string $target): string => 'handled');

    expect($tool->handle(target: 'x'))->toBe('handled');
});
