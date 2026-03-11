# Streaming Output

Want to show AI responses to your users in real-time? Prism provides multiple ways to handle streaming AI responses, from simple Server-Sent Events to WebSocket broadcasting for real-time applications.

> [!WARNING]
> When using Laravel Telescope or other packages that intercept Laravel's HTTP client events, they may consume the stream before Prism can emit the stream events. This can cause streaming to appear broken or incomplete. Consider disabling such interceptors when using streaming functionality, or configure them to ignore Prism's HTTP requests.

## Quick Start

### Server-Sent Events (SSE)

The simplest way to stream AI responses to a web interface:

```php
Route::get('/chat', function () {
    return Prism::text()
        ->using('anthropic', 'claude-3-7-sonnet')
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
        ->using('openai', 'gpt-4')
        ->withPrompt(request('message'))
        ->asDataStreamResponse();
});
```

Client-side with the `useChat` hook:

```javascript
import { useChat } from '@ai-sdk/react';
import { useState } from 'react';

export default function Chat() {
    // AI SDK 5.0 no longer manages input state, so we handle it ourselves
    const [input, setInput] = useState('');

    const { messages, sendMessage, status } = useChat({
        transport: {
            api: '/api/chat',
        },
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (input.trim() && status === 'ready') {
            sendMessage(input);
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

> [!NOTE]
> This example uses AI SDK 5.0, which introduced significant changes to the `useChat` hook. The hook no longer manages input state internally, and you'll need to use the `sendMessage` function directly instead of `handleSubmit`.

For more advanced usage, including tool support and custom options, see the [Vercel AI SDK UI documentation](https://ai-sdk.dev/docs/reference/ai-sdk-ui).

### WebSocket Broadcasting with Background Jobs

For real-time multi-user applications that need to process AI requests in the background:

```php
// Job Class
<?php

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
        public string $model = 'claude-3-7-sonnet'
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
        request('model', 'claude-3-7-sonnet')
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
            console.log('Step finished:', data);
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
                model: 'claude-3-7-sonnet' 
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
- **`artifact`** - Binary artifacts produced by tools (images, audio, files)
- **`provider_tool_event`** - Provider-specific tool events (e.g., image generation, web search)
- **`step_finish`** - End of a generation step (emitted after tool calls or before stream end)
- **`error`** - Error handling with recovery information
- **`stream_end`** - Stream completion with usage statistics

> [!TIP]
> **Understanding Steps**: A "step" represents one cycle of AI generation. In a simple request without tools, there's typically one step. When using tools, each cycle of "AI generates → tools execute → AI continues" creates a new step. Use `step_start` and `step_finish` events to track these cycles in multi-turn tool interactions.

### Event Data Examples

Based on actual streaming output:

```javascript
// stream_start event
{
    "id": "anthropic_evt_SSrB7trNIXsLkbUB",
    "timestamp": 1756412888,
    "model": "claude-3-7-sonnet-20250219",
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

// step_finish event
{
    "id": "anthropic_evt_def456step",
    "timestamp": 1756412895
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
import { useState } from 'react';

export default function Chat() {
    const [input, setInput] = useState('');
    const [artifacts, setArtifacts] = useState([]);

    const { messages, sendMessage, status, data } = useChat({
        transport: {
            api: '/api/chat',
        },
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
                    artifact.mime_type.startsWith('image/') && (
                        <img
                            key={artifact.id || i}
                            src={`data:${artifact.mime_type};base64,${artifact.data}`}
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
                    sendMessage(input);
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
    ->using('anthropic', 'claude-3-7-sonnet')
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

Need to save a conversation to your database after the AI finishes responding? Pass a callback directly to your terminal method to handle the completed response. This is perfect for persisting conversations, tracking analytics, or logging AI interactions.

#### Text Generation Callbacks

For non-streaming requests, pass a callback to `asText()`:

```php
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;

$response = Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet')
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
    ->using('anthropic', 'claude-3-7-sonnet')
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
    ->using('anthropic', 'claude-3-7-sonnet')
    ->withPrompt($message)
    ->asEventStreamResponse(new SaveStreamedConversation($conversationId));
```

### Custom Event Processing

Access raw events for complete control over handling:

```php
$events = Prism::text()
    ->using('openai', 'gpt-4')
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
    ->using('anthropic', 'claude-3-7-sonnet')
    ->withTools([$searchTool])
    ->withPrompt("What's the weather in Detroit?")
    ->asEventStreamResponse();
```

### Data Protocol Output

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

## Configuration Options

Streaming supports all the same configuration options as regular [text generation](/core-concepts/text-generation#generation-parameters), including temperature, max tokens, and provider-specific settings.
