# Streaming Output

Stream generated content to clients with Server-Sent Events, the Vercel AI SDK data protocol or WebSocket broadcasting.

> [!WARNING]
> When using Laravel Telescope or other packages that intercept Laravel's HTTP client events, they may consume the stream before Prism can emit the stream events. This can cause streaming to appear broken or incomplete. Consider disabling such interceptors when using streaming functionality, or configure them to ignore Prism's HTTP requests.

## Quick Start

### Server-Sent Events (SSE)

The simplest way to stream AI responses to a web interface:

```php
Route::get('/chat', function () {
    return Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withPrompt(request('message'))
        ->asEventStreamResponse();
});
```

```javascript
const eventSource = new EventSource('/chat');

eventSource.addEventListener('text_delta', (event) => {
    const data = JSON.parse(event.data);
    document.getElementById('output').textContent += data.delta;
});

eventSource.addEventListener('stream_end', (event) => {
    const data = JSON.parse(event.data);
    console.log('Stream ended:', data.finish_reason);
    eventSource.close();
});
```

### Vercel AI SDK Integration

For apps using Vercel's AI SDK, use the Data Protocol adapter which provides compatibility with the [Vercel AI SDK UI](https://ai-sdk.dev/docs/reference/ai-sdk-ui):

```php
Route::post('/api/chat', function () {
    return Prism::text()
        ->using('openai', 'gpt-4.1')
        ->withPrompt(request('message'))
        ->asDataStreamResponse();
});
```

Client-side with the `useChat` hook:

```javascript
import { useChat } from '@ai-sdk/react';
import { DefaultChatTransport } from 'ai';
import { useState } from 'react';

export default function Chat() {
    // AI SDK 5.0 no longer manages input state, so we handle it ourselves
    const [input, setInput] = useState('');

    const { messages, sendMessage, status } = useChat({
        transport: new DefaultChatTransport({
            api: '/api/chat',
            prepareSendMessagesRequest: ({ messages }) => ({
                body: {
                    message: messages.at(-1).parts
                        .filter(part => part.type === 'text')
                        .map(part => part.text).join('\n'),
                },
            }),
        }),
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (input.trim() && status === 'ready') {
            sendMessage({ text: input });
            setInput('');
        }
    };

    return (
        <div>
            <div>
                {messages.map(m => (
                    <div key={m.id}>
                        <strong>{m.role}:</strong>{' '}
                        {m.parts
                            .filter(part => part.type === 'text')
                            .map(part => part.text)
                            .join('')}
                    </div>
                ))}
            </div>

            <form onSubmit={handleSubmit}>
                <input
                    value={input}
                    placeholder="Say something..."
                    onChange={(e) => setInput(e.target.value)}
                    disabled={status !== 'ready'}
                />
                <button type="submit" disabled={status !== 'ready'}>
                    {status === 'streaming' ? 'Sending...' : 'Send'}
                </button>
            </form>
        </div>
    );
}
```

This example uses the AI SDK 5 transport API and sends the latest user message as `message`, matching the Laravel route above. To retain conversation history, validate the incoming messages and map them to Prism message objects in your application. Apply your application's authentication and rate limits to the route. See [AI SDK transport configuration](https://ai-sdk.dev/docs/ai-sdk-ui/transport).

For more advanced usage, including tool support and custom options, see the [Vercel AI SDK UI documentation](https://ai-sdk.dev/docs/reference/ai-sdk-ui).

### WebSocket Broadcasting with Background Jobs

For real-time multi-user applications that need to process AI requests in the background:

```php
<?php

// Job class

namespace App\Jobs;

use Illuminate\Broadcasting\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Prism\Prism\Facades\Prism;

class ProcessAiStreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $message,
        public string $channel,
        public string $model = 'claude-sonnet-5-5'
    ) {}

    public function handle(): void
    {
        Prism::text()
            ->using('anthropic', $this->model)
            ->withPrompt($this->message)
            ->asBroadcast(new Channel($this->channel));
    }
}

// Controller
Route::post('/chat-broadcast', function () {
    $sessionId = request('session_id') ?? 'session_' . uniqid();
    
    ProcessAiStreamJob::dispatch(
        request('message'),
        "chat.{$sessionId}",
        request('model', 'claude-sonnet-5-5')
    );
    
    return response()->json(['status' => 'processing', 'session_id' => $sessionId]);
});
```

Client-side with React and useEcho:

```javascript
import { useEcho } from '@/hooks/useEcho';
import { useState } from 'react';

function ChatComponent() {
    const [currentMessage, setCurrentMessage] = useState('');
    const [currentMessageId, setCurrentMessageId] = useState('');
    const [isComplete, setIsComplete] = useState(false);

    const sessionId = 'session_' + Date.now();

    // Listen for streaming events
    useEcho(`chat.${sessionId}`, {
        '.stream_start': (data) => {
            console.log('Stream started:', data);
            setCurrentMessage('');
            setIsComplete(false);
        },
        
        '.step_start': (data) => {
            console.log('Step started:', data);
            // A new generation cycle is beginning
        },
        
        '.text_start': (data) => {
            console.log('Text start event received:', data);
            setCurrentMessage('');
            setCurrentMessageId(data.message_id || Date.now().toString());
        },
        
        '.text_delta': (data) => {
            console.log('Text delta received:', data);
            setCurrentMessage(prev => prev + data.delta);
        },
        
        '.text_complete': (data) => {
            console.log('Text complete:', data);
        },
        
        '.tool_call': (data) => {
            console.log('Tool called:', data.tool_name, data.arguments);
        },
        
        '.tool_result': (data) => {
            console.log('Tool result:', data.result);
        },
        
        '.step_finish': (data) => {
            console.log('Step finished:', data.finish_reason);
            // Generation cycle complete, may be followed by another step
        },
        
        '.stream_end': (data) => {
            console.log('Stream ended:', data.finish_reason);
            setIsComplete(true);
        },
        
        '.error': (data) => {
            console.error('Stream error:', data.message);
        }
    });

    const sendMessage = async (message) => {
        await fetch('/chat-broadcast', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                message, 
                session_id: sessionId,
                model: 'claude-sonnet-5-5'
            })
        });
    };

    return (
        <div>
            <div className="message-display">
                {currentMessage}
                {!isComplete && <span className="cursor">|</span>}
            </div>
            
            <button onClick={() => sendMessage("What's the weather in Detroit?")}>
                Send Message
            </button>
        </div>
    );
}
```

## Event Types

All streaming approaches emit the same core events with consistent data structures:

### Available Events

- **`stream_start`** - Stream initialization with model and provider info
- **`step_start`** - Beginning of a generation step (emitted before each AI response cycle)
- **`text_start`** - Beginning of a text message
- **`text_delta`** - Incremental text chunks as they're generated
- **`text_complete`** - End of a complete text message
- **`thinking_start`** - Beginning of AI reasoning/thinking session
- **`thinking_delta`** - Reasoning content as it's generated
- **`thinking_complete`** - End of reasoning session
- **`tool_call`** - Tool invocation with arguments
- **`tool_result`** - Tool execution results
- **`tool_call_delta`** - Incremental tool call params chunks as they're generated
- **`tool_approval_request`** - A custom tool call awaiting an application approval decision
- **`citation`** - A source citation associated with a message and content block
- **`artifact`** - Binary artifacts produced by tools (images, audio, files)
- **`provider_tool_event`** - Provider-specific tool events (e.g., image generation, web search)
- **`step_finish`** - End of a generation step (emitted after tool calls or before stream end)
- **`error`** - An error the provider reported mid-stream. Its `recoverable` flag is `false`, since the error ends the step
- **`stream_end`** - Stream completion with usage statistics

> [!TIP]
> **Understanding Steps**: A "step" represents one cycle of AI generation. In a simple request without tools, there's typically one step. When using tools, each cycle of "AI generates → tools execute → AI continues" creates a new step. Use `step_start` and `step_finish` events to track these cycles in multi-turn tool interactions.

### Event Data Examples

The PHP event classes live under `Prism\Prism\Streaming\Events`. Use `StreamEventType` to identify them:

| Event name | PHP class |
|---|---|
| `stream_start`, `stream_end` | `StreamStartEvent`, `StreamEndEvent` |
| `step_start`, `step_finish` | `StepStartEvent`, `StepFinishEvent` |
| `text_start`, `text_delta`, `text_complete` | `TextStartEvent`, `TextDeltaEvent`, `TextCompleteEvent` |
| `thinking_start`, `thinking_delta`, `thinking_complete` | `ThinkingStartEvent`, `ThinkingEvent`, `ThinkingCompleteEvent` |
| `tool_call`, `tool_call_delta`, `tool_result` | `ToolCallEvent`, `ToolCallDeltaEvent`, `ToolResultEvent` |
| `tool_approval_request`, `citation` | `ToolApprovalRequestEvent`, `CitationEvent` |
| `artifact`, `provider_tool_event`, `error` | `ArtifactEvent`, `ProviderToolEvent`, `ErrorEvent` |

SSE names the event separately from its JSON `data`. Payload fields use snake case; PHP properties use camel case and can contain DTOs. Every payload has `id` and `timestamp`. The following fields supplement the examples below:

| Event | SSE payload fields | PHP access |
|---|---|---|
| `error` | `error_type`, `message`, `recoverable`, `metadata` | `$event->errorType`, `message`, `recoverable`, `metadata` |
| `tool_result` | `tool_id`, `result`, `tool_call_result_id`, `message_id`, `success`, `error` | Result data is in `$event->toolResult`; success/error are on the event |
| `thinking_start` | `reasoning_id` | `$event->reasoningId` |
| `thinking_delta` | `delta`, `reasoning_id`, `summary` | `$event->delta`, `reasoningId`, `summary` |
| `thinking_complete` | `reasoning_id`, `summary` | `$event->reasoningId`, `summary` |
| `citation` | `citation`, `message_id`, `block_index`, `metadata` | `$event->citation`, `messageId`, `blockIndex`, `metadata` |
| `tool_approval_request` | `approval_id`, `tool_id`, `tool_name`, `arguments`, `message_id` | `$event->approvalId`, `toolCall`, `messageId` |

Citation data includes `source_type`, `source`, `source_text`, `source_title`, `source_position_type`, `source_start_index`, `source_end_index` and `additional_content`. Nullable fields remain null when not supplied. Read a tool's name and arguments through `$event->toolCall->name` and `$event->toolCall->arguments()`; they are not flat event properties. See [approval resumption](/core-concepts/human-in-the-loop#streaming) before persisting pending calls.

Example event payloads:

```javascript
// stream_start event
{
    "id": "anthropic_evt_SSrB7trNIXsLkbUB",
    "timestamp": 1756412888,
    "model": "claude-sonnet-5-5",
    "provider": "anthropic",
    "metadata": {
        "request_id": "msg_01BS7MKgXvUESY8yAEugphV2",
        "rate_limits": []
    }
}

// step_start event
{
    "id": "anthropic_evt_abc123step",
    "timestamp": 1756412888
}

// text_start event
{
    "id": "anthropic_evt_8YI9ULcftpFtHzh3",
    "timestamp": 1756412888,
    "message_id": "msg_01BS7MKgXvUESY8yAEugphV2"
}

// text_delta event
{
    "id": "anthropic_evt_NbS3LIP0QDl5whYu",
    "timestamp": 1756412888,
    "delta": "💠🌐 Well hello there! You want to know",
    "message_id": "msg_01BS7MKgXvUESY8yAEugphV2"
}

// tool_call event
{
    "id": "anthropic_evt_qXvozT6OqtmFPgkG",
    "timestamp": 1756412889,
    "tool_id": "toolu_01NAbzpjGxv2mJ8gJRX5Bb8m",
    "tool_name": "search",
    "arguments": {"query": "current date and time in Detroit Michigan"},
    "message_id": "msg_01BS7MKgXvUESY8yAEugphV2",
    "reasoning_id": null
}

// provider_tool_event (e.g., OpenAI image generation)
{
    "id": "openai_evt_abc123",
    "timestamp": 1756412890,
    "type": "provider_tool_event",
    "event_key": "provider_tool_event.image_generation_call.completed",
    "tool_type": "image_generation_call",
    "status": "completed",
    "item_id": "ig_abc123def456",
    "data": {
        "id": "ig_abc123def456",
        "type": "image_generation_call",
        "status": "completed",
        "result": "iVBORw0KGgo..." // base64 PNG data
    }
}

// artifact event (from tool output)
{
    "id": "anthropic_evt_xyz789",
    "timestamp": 1756412891,
    "tool_call_id": "toolu_01NAbzpjGxv2mJ8gJRX5Bb8m",
    "tool_name": "generate_image",
    "message_id": "msg_01BS7MKgXvUESY8yAEugphV2",
    "artifact": {
        "id": "img-abc123",
        "data": "iVBORw0KGgo...", // base64 encoded data
        "mime_type": "image/png",
        "metadata": {
            "width": 1024,
            "height": 1024
        }
    }
}

// step_finish event (finish_reason and usage cover this step only; usage is null when the provider didn't report it)
{
    "id": "anthropic_evt_def456step",
    "timestamp": 1756412895,
    "finish_reason": "ToolCalls",
    "usage": {
        "prompt_tokens": 1720,
        "completion_tokens": 96,
        "cache_write_input_tokens": 0,
        "cache_read_input_tokens": 0,
        "thought_tokens": 41
    }
}

// stream_end event
{
    "id": "anthropic_evt_BZ3rqDYyprnywNyL",
    "timestamp": 1756412898,
    "finish_reason": "Stop",
    "usage": {
        "prompt_tokens": 3448,
        "completion_tokens": 192,
        "cache_write_input_tokens": 0,
        "cache_read_input_tokens": 0,
        "thought_tokens": 0
    }
}
```

The `finish_reason` on `stream_end` reports why the model really stopped, so watch for `Refusal`, `Length` and `Error` as well as `Stop`. Each `step_finish` carries the same field for its own step, so a step that ran tools reports `ToolCalls` even when the stream as a whole ends with `Stop`. The same values are covered in [Why Generation Stopped](/core-concepts/text-generation#why-generation-stopped).

Every stream that isn't cut short by an exception ends the same way on every provider: a `step_finish` for the last step, then exactly one `stream_end`. A few cases worth knowing:

- If `maxSteps` runs out on a step that ended with tool calls, Prism runs those tools and stops there. That step's `step_finish` and the `stream_end` both report `ToolCalls`, just like `$response->finishReason` on a non-streaming request.
- If the connection closes before the provider sends a finish reason, the stream still ends with `step_finish` and `stream_end`, reporting `Stop`.
- If the provider repeats its finish reason across several chunks, you still get one `stream_end`.
- If Anthropic, Groq, Ollama, OpenRouter or Perplexity reports an error partway through (other than the rate limits covered below), or an OpenAI response fails (`response.failed`), you get an `error` event with `recoverable` set to `false`. The step stops there, and its `step_finish` and the `stream_end` both report `Error` so partial output never looks like a finished answer. Any tool calls the step streamed don't run.
- Every `text_start` and `thinking_start` gets its matching `text_complete` or `thinking_complete` before any `tool_call` event that follows it and before its step's `step_finish`, even when the connection closes early.

Some failures throw an exception from inside the stream instead of sending an `error` event, and an exception stops iteration right there: no `error` event, no `step_finish` and no `stream_end`. You'll see one when Groq or Perplexity sends a `rate_limit_exceeded` error mid-stream (`PrismRateLimitedException`), when OpenAI sends an `error` event (`PrismRateLimitedException` for rate limits, `PrismException` for anything else), when a chunk can't be decoded (`PrismStreamDecodeException`), or when the request for the next tool step fails. If you iterate the stream yourself, wrap the loop in a `try`/`catch` so you can close out the response:

```php
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    foreach ($stream as $event) {
        // Handle each event...
    }
} catch (PrismRateLimitedException $e) {
    // Back off and try again later
} catch (PrismException $e) {
    // The stream stopped without a stream_end, so finish the response here
}
```

`asDataStreamResponse()` already catches these for you and sends the client an error before it closes the stream.

## Handling Artifact Events

When tools produce binary artifacts (images, audio, files), they're emitted as `ArtifactEvent` through the stream. This lets your application handle binary data without it going to the LLM's context window.

### Artifact Events with SSE

Listen for artifact events alongside other stream events:

```javascript
const eventSource = new EventSource('/chat');

eventSource.addEventListener('artifact', (event) => {
    const data = JSON.parse(event.data);

    // Display an image artifact
    if (data.artifact.mime_type.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = `data:${data.artifact.mime_type};base64,${data.artifact.data}`;
        document.getElementById('artifacts').appendChild(img);
    }

    // Handle other artifact types
    console.log('Artifact received:', {
        toolName: data.tool_name,
        mimeType: data.artifact.mime_type,
        metadata: data.artifact.metadata,
    });
});

eventSource.addEventListener('text_delta', (event) => {
    const data = JSON.parse(event.data);
    document.getElementById('output').textContent += data.delta;
});
```

### Artifact Events with Vercel AI SDK

When using `asDataStreamResponse()`, artifacts are sent as custom data parts with type `data-artifact`:

```javascript
import { useChat } from '@ai-sdk/react';
import { DefaultChatTransport } from 'ai';
import { useState } from 'react';

export default function Chat() {
    const [input, setInput] = useState('');
    const [artifacts, setArtifacts] = useState([]);

    const { messages, sendMessage, status } = useChat({
        transport: new DefaultChatTransport({
            api: '/api/chat',
            prepareSendMessagesRequest: ({ messages }) => ({
                body: {
                    message: messages.at(-1).parts
                        .filter(part => part.type === 'text')
                        .map(part => part.text).join('\n'),
                },
            }),
        }),
        onData: (data) => {
            // Handle artifact data messages
            if (data.type === 'data-artifact') {
                setArtifacts(prev => [...prev, data.data.artifact]);
            }
        },
    });

    return (
        <div>
            {/* Display artifacts */}
            <div className="artifacts">
                {artifacts.map((artifact, i) => (
                    artifact.mimeType.startsWith('image/') && (
                        <img
                            key={artifact.id || i}
                            src={`data:${artifact.mimeType};base64,${artifact.data}`}
                            alt={`Generated artifact ${i + 1}`}
                        />
                    )
                ))}
            </div>

            {/* Messages display */}
            <div>
                {messages.map(m => (
                    <div key={m.id}>
                        <strong>{m.role}:</strong>{' '}
                        {m.parts
                            .filter(part => part.type === 'text')
                            .map(part => part.text)
                            .join('')}
                    </div>
                ))}
            </div>

            <form onSubmit={(e) => {
                e.preventDefault();
                if (input.trim() && status === 'ready') {
                    sendMessage({ text: input });
                    setInput('');
                }
            }}>
                <input
                    value={input}
                    placeholder="Ask to generate an image..."
                    onChange={(e) => setInput(e.target.value)}
                />
                <button type="submit">Send</button>
            </form>
        </div>
    );
}
```

### Artifact Events with Broadcasting

When using `asBroadcast()` for WebSocket broadcasting, listen for the `.artifact` event:

```javascript
useEcho(`chat.${sessionId}`, {
    '.artifact': (data) => {
        console.log('Artifact received:', data.tool_name);

        // Store or display the artifact
        if (data.artifact.mime_type.startsWith('image/')) {
            setImages(prev => [...prev, {
                id: data.artifact.id,
                src: `data:${data.artifact.mime_type};base64,${data.artifact.data}`,
                metadata: data.artifact.metadata,
            }]);
        }
    },

    '.tool_result': (data) => {
        console.log('Tool result (text for LLM):', data.result);
    },

    // ... other event handlers
});
```

### Persisting Artifacts in Callbacks

Use streaming callbacks to save artifacts to your database or storage:

```php
use Illuminate\Support\Collection;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\PendingRequest;

return Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$imageGeneratorTool])
    ->withPrompt(request('message'))
    ->asDataStreamResponse(function (PendingRequest $request, Collection $events) use ($conversationId) {
        // Save artifacts to storage
        $events
            ->filter(fn (StreamEvent $event) => $event instanceof ArtifactEvent)
            ->each(function (ArtifactEvent $event) use ($conversationId) {
                Attachment::create([
                    'conversation_id' => $conversationId,
                    'tool_call_id' => $event->toolCallId,
                    'tool_name' => $event->toolName,
                    'mime_type' => $event->artifact->mimeType,
                    'data' => $event->artifact->rawContent(), // Decoded binary data
                    'metadata' => $event->artifact->metadata,
                ]);
            });
    });
```

For more information about creating tools that produce artifacts, see [Tool Artifacts](/core-concepts/tools-function-calling#tool-artifacts).

## Advanced Usage

### Handling Completion with Callbacks

Pass a callback to the terminal method to process the completed response or collected stream events. Use callbacks to persist conversations or record usage.

#### Text Generation Callbacks

For non-streaming requests, pass a callback to `asText()`:

```php
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(request('message'))
    ->asText(function (PendingRequest $request, Response $response) use ($conversationId) {
        // Save the response to your database
        ConversationMessage::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $response->text,
            'tool_calls' => $response->toolCalls,
        ]);
    });

// The response is still returned for further use
return response()->json(['message' => $response->text]);
```

The callback receives the `PendingRequest` and the complete `Response` object, giving you access to the full response including text, tool calls, tool results, and usage statistics.

#### Streaming Response Callbacks

For streaming responses, pass a callback to receive all collected events when the stream completes:

```php
use Illuminate\Support\Collection;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;

return Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(request('message'))
    ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use ($conversationId) {
        // Reconstruct the full text from all delta events
        $fullText = $events
            ->filter(fn (StreamEvent $event) => $event instanceof TextDeltaEvent)
            ->map(fn (TextDeltaEvent $event) => $event->delta)
            ->join('');

        // Save the complete response
        ConversationMessage::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $fullText,
        ]);
    });
```

The callback receives:
- `PendingRequest` - The original request configuration
- `Collection<StreamEvent>` - All events that occurred during the stream

This works with all streaming methods: `asEventStreamResponse()`, `asDataStreamResponse()`, and `asBroadcast()`.

#### Using Invokable Classes

For better organization, use invokable classes as callbacks:

```php
use Illuminate\Support\Collection;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\PendingRequest;

class SaveStreamedConversation
{
    public function __construct(
        protected string $conversationId
    ) {}

    public function __invoke(PendingRequest $request, Collection $events): void
    {
        $fullText = $events
            ->filter(fn (StreamEvent $event) => $event instanceof TextDeltaEvent)
            ->map(fn (TextDeltaEvent $event) => $event->delta)
            ->join('');

        ConversationMessage::create([
            'conversation_id' => $this->conversationId,
            'role' => 'assistant',
            'content' => $fullText,
        ]);
    }
}

// Use with streaming responses
return Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt($message)
    ->asEventStreamResponse(new SaveStreamedConversation($conversationId));
```

### Custom Event Processing

Access raw events for complete control over handling:

```php
$events = Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withPrompt('Explain quantum physics')
    ->asStream();

foreach ($events as $event) {
    match ($event->type()) {
        StreamEventType::TextDelta => handleTextChunk($event),
        StreamEventType::ToolCall => handleToolCall($event),
        StreamEventType::StreamEnd => handleCompletion($event),
        default => null,
    };
}
```

### Streaming with Tools

Stream responses that include tool interactions:

```php
use Prism\Prism\Facades\Tool;

$searchTool = Tool::as('search')
    ->for('Search for information')
    ->withStringParameter('query', 'Search query')
    ->using(function (string $query) {
        return "Search results for: {$query}";
    });

return Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$searchTool])
    ->withPrompt("What's the weather in Detroit?")
    ->asEventStreamResponse();
```

Prism only runs your tools when a step actually ends with tool calls. If the model is cut off by `maxTokens`, refuses, or the provider reports a failure partway through a step, any tool calls from that step are not executed.

### Persisting Streamed Conversations

The callbacks above hand you the raw events. When you want to continue the conversation later, you need messages instead: each assistant turn with its tool calls, its tool results, and the provider state that newer models expect back. `StreamedMessages::fromEvents()` rebuilds them from the events your callback already receives:

```php
use Illuminate\Support\Collection;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\StreamedMessages;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$userMessage = new UserMessage(request('message'));

return Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withMessages([...$conversation->messages(), $userMessage])
    ->withTools([$searchTool])
    ->withMaxSteps(5)
    ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use ($conversation, $userMessage) {
        // One assistant message per step, followed by that step's tool results
        $messages = StreamedMessages::fromEvents($events);

        $conversation->append([$userMessage, ...$messages]);
    });
```

The same callback works with `asDataStreamResponse()` and `asBroadcast()`.

You only get back the messages the stream produced. The messages you sent aren't included, so store your own user message (or whatever input you passed) ahead of them, like `$userMessage` above.

Each `AssistantMessage` carries its step's replay data in `additionalContent`, such as Claude's thinking signatures or OpenAI's reasoning items. Store that along with the text, then pass the stored messages back with `withMessages()` on the next turn. See [Continuing a Conversation](/core-concepts/text-generation#continuing-a-conversation) for why this matters.

A few details worth knowing:

- Tool calls from a step that didn't end with tool calls (a refusal, a `maxTokens` cutoff, a failure) never ran, so they're left out of that step's `AssistantMessage`.
- If the stream stops early, you get the messages for what arrived. Tool calls that never got a result are left out, so the stored conversation still replays cleanly.
- If the client disconnects from `asEventStreamResponse()` or `asDataStreamResponse()` after a step's tools have run, Prism collects the rest of that step before calling your callback: the remaining tool results and the step's replay data, which are already computed. It doesn't send the next request, so no more tools run, and you keep the results of the tools that did.
- Outside a callback, collect the events from `asStream()` yourself and pass them in: `StreamedMessages::fromEvents(iterator_to_array($stream, false))`.

> [!IMPORTANT]
> By default, PHP ends your script when it tries to send output to a client that has disconnected, so your callback may never run. If you persist every turn, call `ignore_user_abort(true)` before you return the response. Prism then notices the disconnect, stops streaming, and calls your callback with what it collected. That's how PHP-FPM and Apache behave. Octane servers manage the connection themselves, so check how yours handles a disconnect.

### Data Protocol Output

`asDataStreamResponse()` converts PHP events to AI SDK UI-message parts:

| Prism event | Data-protocol part |
|---|---|
| Stream and step boundaries | `start`, `finish`, `start-step`, `finish-step` |
| Text boundaries and deltas | `text-start`, `text-delta`, `text-end` |
| Thinking boundaries and deltas | `reasoning-start`, `reasoning-delta`, `reasoning-end` |
| Custom tool call | `tool-input-available` with `toolCallId`, `toolName`, `input` |
| Custom tool result | `tool-output-available`, or `tool-output-error` when unsuccessful |
| Artifact | `data-artifact`; artifact fields use `mimeType` in this protocol |
| Citation, pending approval, tool-call delta | `data-citation`, `data-tool_approval_request`, `data-tool_call_delta`; `data` holds the event's serialized payload |
| Error | `error` with `errorText` |

Provider tool events are converted to tool-input/output parts when applicable. The stream ends with `data: [DONE]`. Step replay state in `StepFinishEvent::$additionalContent` is available to PHP callbacks and is not part of the serialized step-finish payload; persist it server-side through those callbacks.

The Vercel AI SDK format provides structured streaming data:

```
data: {"type":"start","messageId":"anthropic_evt_NPbGJs7D0oQhvz2K"}

data: {"type":"start-step"}

data: {"type":"text-start","id":"msg_013P3F8KkVG3Qasjeay3NUmY"}

data: {"type":"text-delta","id":"msg_013P3F8KkVG3Qasjeay3NUmY","delta":"Hello"}

data: {"type":"text-end","id":"msg_013P3F8KkVG3Qasjeay3NUmY"}

data: {"type":"finish-step"}

data: {"type":"finish","messageMetadata":{"finishReason":"stop","usage":{"promptTokens":1998,"completionTokens":288}}}

data: [DONE]
```

## Usage after a failed stream

An `ErrorEvent` can carry `failureUsage` when the provider reported usage before the failure. It contains the full `Usage`, `status: provisional`, `scope: attempt`, a `stepIndex` and the normalized `reportedFields`. The counts cover that failed request and exclude earlier completed steps. They are not a completed turn or a final bill. Missing usage is null; an explicit zero remains zero. Serialized errors omit `failure_usage` when it is null.

```php
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Events\ErrorEvent;

$failureUsage = null;

try {
    foreach (Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withPrompt('Summarize the document.')
        ->asStream() as $event) {
        if ($event instanceof ErrorEvent) {
            $failureUsage = $event->failureUsage;
        }

        // Process the event.
    }
} catch (PrismException $exception) {
    $failureUsage = $exception->streamFailureUsage() ?? $failureUsage;
    throw $exception;
}
```

SDK exceptions expose the optional payload through `streamFailureUsage()`. When usage was reported, a foreign transport exception causes one final `ErrorEvent` before the read exception propagates. Without reported usage, no extra event is emitted. Existing provider HTTP exception mappings remain in effect: their mapped SDK exception receives the snapshot. Other foreign exceptions propagate unchanged and have no usage accessor: retain the final event or read [failure telemetry](/advanced/telemetry). Iteration can therefore fail after yielding an event; `iterator_to_array()` does not return its partial result when an exception is thrown.

Record each attempt once. An error event, an exception and an Error terminal can describe the same attempt; do not add their copies of usage together. Use `reportedFields` to distinguish a reported zero from an unavailable field. Successful `Usage` and completed-step accounting retain their existing shape.

## Configuration Options

Streaming supports all the same configuration options as regular [text generation](/core-concepts/text-generation#generation-parameters), including temperature, max tokens, and provider-specific settings.
