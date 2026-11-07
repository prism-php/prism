# Human-in-the-Loop

Some tools are too consequential to run automatically — deleting files, sending
money, emailing customers. Prism gives you two ways to keep a human (or your
own application logic) between the model and the action:

- **Client-executed tools** — the model can call the tool, but *your
  application* executes it.
- **Approval-required tools** — execution pauses until an explicit approval
  decision is supplied. **Deny-by-default**: a tool call that never receives an
  approval is refused, never executed.

Use these controls with providers that support custom tools. Approval and
client-executed streaming are supported by Anthropic, Azure, DeepSeek, Gemini,
Groq, Mistral, Ollama, OpenAI, OpenRouter, Qwen, Requesty, Vertex, xAI and Z.

## Client-executed tools

Mark a tool with `clientExecuted()` instead of giving it a handler:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$searchTool = Tool::as('search_crm')
    ->for('Search the CRM for a customer')
    ->withStringParameter('query', 'The search query')
    ->clientExecuted();

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$searchTool])
    ->withMaxSteps(3)
    ->withPrompt('Find the customer named Acme Corp')
    ->asText();
```

When the model calls `search_crm`, the request loop **stops** and the response
comes back with `FinishReason::ToolCalls`. Execute the tool yourself, then
resume by appending your results to the conversation:

```php
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

$pendingCall = $response->toolCalls[0];

$results = new ToolResultMessage([
    new ToolResult(
        toolCallId: $pendingCall->id,
        toolName: $pendingCall->name,
        args: $pendingCall->arguments(),
        result: MyCrm::search($pendingCall->arguments()['query']),
    ),
]);

$final = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$searchTool])
    ->withMessages([...$response->messages, $results])
    ->asText();
```

## Approval-required tools

Keep the handler, but gate it behind `requiresApproval()`:

```php
$deleteTool = Tool::as('delete_file')
    ->for('Delete a file')
    ->withStringParameter('path', 'File path to delete')
    ->using(fn (string $path): string => Storage::delete($path) ? "Deleted {$path}" : "Failed")
    ->requiresApproval();
```

Approval can also be conditional on the arguments:

```php
$transferTool = Tool::as('transfer')
    ->for('Transfer money')
    ->withNumberParameter('amount', 'Amount in dollars')
    ->using(fn (int $amount): string => Bank::transfer($amount))
    ->requiresApproval(fn (array $args): bool => ($args['amount'] ?? 0) > 100);
```

When approval is needed, the loop stops and the pending requests surface on the
assistant message and the step:

```php
$step = $response->steps->last();

foreach ($step->toolApprovalRequests as $request) {
    // $request->approvalId — correlate the decision
    // $request->toolCallId — which tool call it belongs to
}
```

Resume with the decisions — approved calls execute, denied calls produce a
denial result the model sees:

An unanswered approval produces `No approval response provided`. An explicit denial without a reason produces `User denied tool execution`; a supplied reason replaces that default. Approval decisions and the pending call's arguments must be stored and correlated by your application.

```php
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

$decisions = new ToolResultMessage([], [
    new ToolApprovalResponse($request->approvalId, approved: true),
    // or: new ToolApprovalResponse($id, approved: false, reason: 'Too risky'),
]);

$final = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$deleteTool])
    ->withMessages([...$response->messages, $decisions])
    ->asText();
```

::: warning
Approval responses are trusted input from **your application** — Prism
verifies correlation ids, not who clicked the button. Authenticate and
authorize the approving user in your app before sending an approval.
:::

## Streaming

On a supported provider's stream, a pending approval emits a
`ToolApprovalRequestEvent` (`tool_approval_request`) carrying the
`approvalId`, the tool call, and its arguments — then the stream ends with
`FinishReason::ToolCalls`. The event is broadcastable
(`ToolApprovalRequestBroadcast`), so an approval prompt can be pushed to a UI
over websockets in real time.

In PHP, read the call through `$event->toolCall`, including `name` and `arguments()`. Its SSE payload uses `approval_id`, `tool_id`, `tool_name`, `arguments` and `message_id`, alongside the event ID and timestamp.

The following Anthropic example pauses after one generation step and retains the approval correlation for a later request. It uses the `$deleteTool` defined above:

```php
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;

$history = [new UserMessage('Delete reports/draft.txt.')];
$events = iterator_to_array(Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$deleteTool])
    ->withMaxSteps(1)
    ->withMessages($history)
    ->asStream(), false);

$approvalEvents = array_values(array_filter($events,
    fn ($event): bool => $event instanceof ToolApprovalRequestEvent));
$end = collect($events)->last(fn ($event): bool => $event instanceof StreamEndEvent);

if ($approvalEvents === [] || $end?->finishReason !== FinishReason::ToolCalls) {
    throw new RuntimeException('Handle the completed or failed turn separately.');
}

$generated = StreamedMessages::fromEvents($events)->map(function ($message) use ($approvalEvents) {
    if (! $message instanceof AssistantMessage) {
        return $message;
    }

    $requests = [];
    foreach ($approvalEvents as $event) {
        foreach ($message->toolCalls as $call) {
            if ($call->id === $event->toolCall->id) {
                $requests[] = new ToolApprovalRequest($event->approvalId, $call->id);
            }
        }
    }

    return new AssistantMessage(
        content: $message->content,
        toolCalls: $message->toolCalls,
        additionalContent: $message->additionalContent,
        toolApprovalRequests: $requests,
    );
});

$history = [...$history, ...$generated];
// Persist $history and $approvalEvents server-side, then show the pending calls.
```

`StreamedMessages::fromEvents()` currently retains Anthropic's completed tool step and replay metadata but does not add approval requests to its messages. The example attaches those requests explicitly. Do not rebuild messages from text alone or discard the approval IDs.

After authenticating the user and authorizing the decision, reload that history and resume:

```php
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

// $approvedByUser is the authorized application decision, not model output.
$decisions = new ToolResultMessage([], [
    new ToolApprovalResponse($approvalEvents[0]->approvalId, approved: $approvedByUser),
]);

$stream = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$deleteTool])
    ->withMessages([...$history, $decisions])
    ->withMaxSteps(2)
    ->asStream();
```

Collect and present a decision for every pending approval your application intends to resolve. An omitted decision is denied by default.
