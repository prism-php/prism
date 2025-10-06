# Stream Handler State Refactoring - Implementation Plan

## Executive Summary

Refactor all 9 provider stream handlers to use a unified, fluent `StreamState` object instead of scattered class properties and local variables. This will improve consistency, maintainability, and readability across the streaming architecture.

## Research Findings

### Current State Management Landscape

**Anthropic Discovery**: An unused `StreamState` value object exists at `/src/Providers/Anthropic/ValueObjects/StreamState.php` (286 lines) with fluent methods, but the Stream handler doesn't use it.

**Common State Variables** (across all 9 providers):
1. `messageId` (string) - Unique message identifier
2. `reasoningId` (string) - Unique thinking/reasoning session identifier
3. `streamStarted` (bool) - StreamStartEvent emission flag
4. `textStarted` (bool) - TextStartEvent emission flag
5. `thinkingStarted` (bool) - ThinkingStartEvent emission flag

**Provider-Specific State**:
- **Ollama**: Adds `promptTokens` (int), `completionTokens` (int), `toolCalls` (array) as instance properties
- **Anthropic**: Uses 10 instance properties including `currentBlockIndex`, `currentBlockType`, `currentThinkingSignature`, `citations`
- **OpenAI**: Uses only local variables in `processStream()` method (no instance properties)
- **Gemini**: Uses 6 instance properties similar to Anthropic
- **Others**: Mix of instance properties (5 common flags) and local variables

**Current Problems**:
1. **Inconsistent state storage**: Mix of instance properties vs local variables
2. **Scattered state logic**: Reset methods duplicate similar logic
3. **Hard to track state flow**: State mutations happen throughout handler methods
4. **No clear state lifecycle**: Difficult to understand when state is reset/updated
5. **Testing difficulty**: Can't easily inspect or mock state
6. **Code duplication**: Similar state management patterns repeated across providers

### StreamEvent Requirements Analysis

**12 Event Types** require specific state data:
- `StreamStartEvent`: model, provider, metadata
- `TextStartEvent`: messageId
- `TextDeltaEvent`: messageId, delta
- `TextCompleteEvent`: messageId
- `ThinkingStartEvent`: reasoningId
- `ThinkingEvent`: reasoningId, delta, summary
- `ThinkingCompleteEvent`: reasoningId, summary
- `ToolCallEvent`: messageId, toolCall (ToolCall object)
- `ToolResultEvent`: messageId, toolResult (ToolResult object)
- `CitationEvent`: messageId, blockIndex, citation
- `ErrorEvent`: errorType, message, recoverable, metadata
- `StreamEndEvent`: finishReason, usage, citations

**Critical State Relationships**:
- All text events share same `messageId`
- All thinking events share same `reasoningId`
- ToolResultEvent's `toolCallId` must match ToolCallEvent's `toolCall.id`
- Citations collected throughout stream, included in StreamEndEvent

## Architecture Design

### Core Principles

1. **Single Source of Truth**: All state in one `StreamState` object
2. **Fluent Interface**: Chainable methods returning `$this` for mutations
3. **Immutable IDs**: Once set, messageId/reasoningId don't change (within a turn)
4. **Clear Lifecycle**: Explicit reset points via `reset()`, `resetTextState()`, `resetBlock()`
5. **Provider Extensibility**: Base class can be extended for provider-specific state
6. **Type Safety**: Strong typing on all state properties and methods
7. **Self-Documenting**: Method names clearly indicate state changes

### Base StreamState Class Design

**Location**: `/src/Streaming/StreamState.php` (new file)

**Core Properties**:
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;

class StreamState
{
    // Core Identifiers
    protected string $messageId = '';
    protected string $reasoningId = '';

    // Lifecycle Flags
    protected bool $streamStarted = false;
    protected bool $textStarted = false;
    protected bool $thinkingStarted = false;

    // Content Accumulators
    protected string $currentText = '';
    protected string $currentThinking = '';

    // Block Context (for providers that need it)
    protected ?int $currentBlockIndex = null;
    protected ?string $currentBlockType = null;

    // Tool Calls Collection
    /** @var array<int, array<string, mixed>> */
    protected array $toolCalls = [];

    // Citations Collection
    /** @var array<MessagePartWithCitations> */
    protected array $citations = [];

    // Usage Metadata
    protected ?Usage $usage = null;

    // Finish Reason
    protected ?FinishReason $finishReason = null;

    // Provider Metadata
    protected string $model = '';
    protected string $provider = '';
    protected ?array $metadata = null;
}
```

**Fluent Setters** (ID Management):
```php
public function setMessageId(string $messageId): self
{
    $this->messageId = $messageId;
    return $this;
}

public function setReasoningId(string $reasoningId): self
{
    $this->reasoningId = $reasoningId;
    return $this;
}

public function setModel(string $model): self
{
    $this->model = $model;
    return $this;
}

public function setProvider(string $provider): self
{
    $this->provider = $provider;
    return $this;
}

public function setMetadata(?array $metadata): self
{
    $this->metadata = $metadata;
    return $this;
}
```

**Fluent Flag Methods**:
```php
public function markStreamStarted(): self
{
    $this->streamStarted = true;
    return $this;
}

public function markTextStarted(): self
{
    $this->textStarted = true;
    return $this;
}

public function markThinkingStarted(): self
{
    $this->thinkingStarted = true;
    return $this;
}
```

**Content Accumulation Methods**:
```php
public function appendText(string $text): self
{
    $this->currentText .= $text;
    return $this;
}

public function appendThinking(string $thinking): self
{
    $this->currentThinking .= $thinking;
    return $this;
}

public function setText(string $text): self
{
    $this->currentText = $text;
    return $this;
}

public function setThinking(string $thinking): self
{
    $this->currentThinking = $thinking;
    return $this;
}
```

**Block Context Methods**:
```php
public function setBlockContext(int $index, string $type): self
{
    $this->currentBlockIndex = $index;
    $this->currentBlockType = $type;
    return $this;
}

public function resetBlockContext(): self
{
    $this->currentBlockIndex = null;
    $this->currentBlockType = null;
    return $this;
}
```

**Tool Call Methods**:
```php
public function addToolCall(int $index, array $toolCall): self
{
    $this->toolCalls[$index] = $toolCall;
    return $this;
}

public function appendToolCallInput(int $index, string $input): self
{
    if (!isset($this->toolCalls[$index])) {
        $this->toolCalls[$index] = ['input' => ''];
    }

    $this->toolCalls[$index]['input'] .= $input;
    return $this;
}

public function updateToolCall(int $index, array $data): self
{
    $this->toolCalls[$index] = array_merge(
        $this->toolCalls[$index] ?? [],
        $data
    );
    return $this;
}
```

**Citation Methods**:
```php
public function addCitation(MessagePartWithCitations $citation): self
{
    $this->citations[] = $citation;
    return $this;
}
```

**Metadata Methods**:
```php
public function setUsage(Usage $usage): self
{
    $this->usage = $usage;
    return $this;
}

public function setFinishReason(FinishReason $finishReason): self
{
    $this->finishReason = $finishReason;
    return $this;
}
```

**Helper/Query Methods** (read-only, no fluent return):
```php
public function messageId(): string
{
    return $this->messageId;
}

public function reasoningId(): string
{
    return $this->reasoningId;
}

public function model(): string
{
    return $this->model;
}

public function provider(): string
{
    return $this->provider;
}

public function metadata(): ?array
{
    return $this->metadata;
}

public function hasStreamStarted(): bool
{
    return $this->streamStarted;
}

public function hasTextStarted(): bool
{
    return $this->textStarted;
}

public function hasThinkingStarted(): bool
{
    return $this->thinkingStarted;
}

public function currentText(): string
{
    return $this->currentText;
}

public function currentThinking(): string
{
    return $this->currentThinking;
}

public function currentBlockIndex(): ?int
{
    return $this->currentBlockIndex;
}

public function currentBlockType(): ?string
{
    return $this->currentBlockType;
}

public function toolCalls(): array
{
    return $this->toolCalls;
}

public function hasToolCalls(): bool
{
    return $this->toolCalls !== [];
}

public function citations(): array
{
    return $this->citations;
}

public function usage(): ?Usage
{
    return $this->usage;
}

public function finishReason(): ?FinishReason
{
    return $this->finishReason;
}

public function shouldEmitStreamStart(): bool
{
    return !$this->streamStarted;
}

public function shouldEmitTextStart(): bool
{
    return !$this->textStarted;
}

public function shouldEmitThinkingStart(): bool
{
    return !$this->thinkingStarted;
}
```

**Reset Methods**:
```php
/**
 * Full state reset - used at stream start or between tool call turns
 */
public function reset(): self
{
    $this->messageId = '';
    $this->reasoningId = '';
    $this->streamStarted = false;
    $this->textStarted = false;
    $this->thinkingStarted = false;
    $this->currentText = '';
    $this->currentThinking = '';
    $this->currentBlockIndex = null;
    $this->currentBlockType = null;
    $this->toolCalls = [];
    $this->citations = [];
    $this->usage = null;
    $this->finishReason = null;
    $this->model = '';
    $this->provider = '';
    $this->metadata = null;

    return $this;
}

/**
 * Text state reset - used when continuing with tool calls
 * Preserves: citations, usage, some flags
 */
public function resetTextState(): self
{
    $this->messageId = '';
    $this->textStarted = false;
    $this->thinkingStarted = false;
    $this->currentText = '';
    $this->currentThinking = '';

    return $this;
}

/**
 * Block context reset - used when content block completes
 */
public function resetBlock(): self
{
    $this->currentBlockIndex = null;
    $this->currentBlockType = null;

    return $this;
}
```

### Provider-Specific Extensions

**Ollama Extension** (needs token accumulation):

**Location**: `/src/Providers/Ollama/ValueObjects/OllamaStreamState.php` (new file)

```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class OllamaStreamState extends StreamState
{
    protected int $promptTokens = 0;
    protected int $completionTokens = 0;

    public function addPromptTokens(int $tokens): self
    {
        $this->promptTokens += $tokens;
        return $this;
    }

    public function addCompletionTokens(int $tokens): self
    {
        $this->completionTokens += $tokens;
        return $this;
    }

    public function promptTokens(): int
    {
        return $this->promptTokens;
    }

    public function completionTokens(): int
    {
        return $this->completionTokens;
    }

    public function reset(): self
    {
        parent::reset();
        $this->promptTokens = 0;
        $this->completionTokens = 0;

        return $this;
    }
}
```

**Anthropic Extension** (needs signature tracking):

**Location**: `/src/Providers/Anthropic/ValueObjects/AnthropicStreamState.php` (new file)

```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

use Prism\Prism\Streaming\StreamState;

class AnthropicStreamState extends StreamState
{
    protected string $currentThinkingSignature = '';

    public function appendThinkingSignature(string $signature): self
    {
        $this->currentThinkingSignature .= $signature;
        return $this;
    }

    public function currentThinkingSignature(): string
    {
        return $this->currentThinkingSignature;
    }

    public function reset(): self
    {
        parent::reset();
        $this->currentThinkingSignature = '';

        return $this;
    }

    public function resetTextState(): self
    {
        parent::resetTextState();
        $this->currentThinkingSignature = '';

        return $this;
    }
}
```

**Other Providers**: Use base `StreamState` directly (no extension needed)

## Implementation Plan

### Phase 1: Foundation (Base StreamState Class)

**Goal**: Create the base `StreamState` class and test infrastructure

**Tasks**:
1. ✅ **Create base `StreamState` class** at `/src/Streaming/StreamState.php`
   - Implement all properties, methods as designed above
   - Add PHPDoc for array types, generics
   - Ensure strict typing on all methods

2. ✅ **Create unit tests** at `/tests/Unit/Streaming/StreamStateTest.php`
   - Test all fluent setter methods return `$this`
   - Test all query methods return correct values
   - Test accumulation methods (appendText, appendThinking, addToolCall, etc.)
   - Test reset methods clear appropriate state
   - Test flag methods set/check state correctly
   - Test helper methods (hasToolCalls, shouldEmitTextStart, etc.)

3. ✅ **Verify compilation and static analysis**
   - Run `composer types` - must pass
   - Run `composer format` - must pass
   - Run unit tests - must pass

**Deliverables**:
- `/src/Streaming/StreamState.php` (base class)
- `/tests/Unit/Streaming/StreamStateTest.php` (comprehensive tests)
- All tests passing, zero static analysis errors

**Estimated Complexity**: Medium (new class, comprehensive testing needed)

---

### Phase 2: Provider Extensions

**Goal**: Create provider-specific state extensions where needed

**Tasks**:
1. ✅ **Create `OllamaStreamState` extension**
   - Location: `/src/Providers/Ollama/ValueObjects/OllamaStreamState.php`
   - Add token accumulation properties and methods
   - Override reset methods to clear token counts

2. ✅ **Create `AnthropicStreamState` extension**
   - Location: `/src/Providers/Anthropic/ValueObjects/AnthropicStreamState.php`
   - Add thinking signature property and methods
   - Override reset methods to clear signature

3. ✅ **Create unit tests for extensions**
   - `/tests/Unit/Providers/Ollama/OllamaStreamStateTest.php`
   - `/tests/Unit/Providers/Anthropic/AnthropicStreamStateTest.php`
   - Test provider-specific methods and reset behavior

4. ✅ **Verify all tests pass**

**Deliverables**:
- 2 provider-specific extensions with tests
- All tests passing

**Estimated Complexity**: Low (simple extensions)

---

### Phase 3: Anthropic Stream Handler Refactor

**Goal**: Refactor Anthropic as the reference implementation (since it already has unused StreamState)

**Current State**:
- File: `/src/Providers/Anthropic/Handlers/Stream.php`
- Has 10 instance properties for state management
- Has unused `/src/Providers/Anthropic/ValueObjects/StreamState.php` (DELETE after migration)
- Complex state management across 655 lines

**Tasks**:
1. ✅ **Replace instance properties with state object**
   - Change from individual properties to `protected AnthropicStreamState $state`
   - Initialize in constructor: `$this->state = new AnthropicStreamState()`

2. ✅ **Refactor all state reads**
   - Replace `$this->messageId` → `$this->state->messageId()`
   - Replace `$this->currentText` → `$this->state->currentText()`
   - Replace `$this->streamStarted` → `$this->state->hasStreamStarted()`
   - Apply consistently across all 655 lines

3. ✅ **Refactor all state writes**
   - Replace `$this->messageId = $x` → `$this->state->setMessageId($x)`
   - Replace `$this->currentText .= $x` → `$this->state->appendText($x)`
   - Replace `$this->streamStarted = true` → `$this->state->markStreamStarted()`
   - Use fluent chaining where beneficial

4. ✅ **Replace reset methods**
   - Replace `resetState()` with `$this->state->reset()`
   - Replace `resetCurrentBlock()` with `$this->state->resetBlock()`

5. ✅ **Update conditional checks**
   - Replace `if ($this->toolCalls !== [])` → `if ($this->state->hasToolCalls())`
   - Replace `if (!$this->streamStarted)` → `if ($this->state->shouldEmitStreamStart())`

6. ✅ **Delete old unused StreamState**
   - Remove `/src/Providers/Anthropic/ValueObjects/StreamState.php` (old unused version)

7. ✅ **Run tests**
   - Run Anthropic provider tests: `vendor/bin/pest tests/Providers/Anthropic/StreamTest.php`
   - Verify all 592 lines of tests still pass
   - Run full test suite: `composer test`

**Deliverables**:
- Anthropic Stream handler refactored to use new state object
- Old unused StreamState deleted
- All tests passing (850+ tests)

**Estimated Complexity**: High (655 lines, complex state interactions)

---

### Phase 4: Remaining Provider Refactors (Parallel Execution)

**Goal**: Refactor remaining 8 providers to use StreamState

**Strategy**: Use agents to parallelize work across providers

**Provider Groups** (complexity-based):

**Group A - Simple Providers** (no thinking support, minimal state):
- **Groq** (316 lines, 5 state properties)
- **Mistral** (325 lines, 3 state properties)
- **DeepSeek** (294 lines, 5 state properties)

**Group B - Medium Providers** (thinking support, moderate state):
- **XAI** (365 lines, 5 state properties + thinking)
- **OpenRouter** (439 lines, 5 state properties + thinking)

**Group C - Complex Providers** (unique features):
- **Ollama** (322 lines, needs OllamaStreamState extension)
- **Gemini** (445 lines, dual content streams, cached content)
- **OpenAI** (435 lines, local variables only, no instance properties)

**Tasks per Provider** (template):
1. ✅ Read current Stream handler implementation
2. ✅ Identify which state class to use (base StreamState or extension)
3. ✅ Add state property initialization in constructor/handle method
4. ✅ Refactor all state reads to use state object query methods
5. ✅ Refactor all state writes to use state object fluent methods
6. ✅ Replace reset method calls with state object resets
7. ✅ Update conditionals to use helper methods (hasToolCalls, shouldEmitTextStart, etc.)
8. ✅ Run provider-specific tests
9. ✅ Verify no regressions

**Specific Considerations per Provider**:

**Groq**:
- Has unused `toolCalls` instance property - remove entirely
- Uses `resetState()` and `resetTextState()` - map to state methods
- Local `$text` and `$toolCalls` variables in processStream - can remain local or move to state

**Mistral**:
- Simplest implementation
- No `toolCalls` instance property
- Uses `Str::random(8)` for tool IDs - preserve this logic

**DeepSeek**:
- Uses only method-local variables for accumulation
- Decision: Keep local variables or move to state? (Recommend: move to state for consistency)

**XAI**:
- Supports thinking/reasoning
- Stores finish reason and usage in local variables before emission - can preserve or move to state
- Uses `array_map` for tool call mapping - preserve

**OpenRouter**:
- Very similar to DeepSeek
- Local `reasoning` accumulator - move to state
- Uses EventID prefix for IDs - preserve

**Ollama**:
- **MUST use OllamaStreamState extension**
- Has token accumulation that needs special handling
- Instance property `toolCalls` - move to state
- Uses `addPromptTokens()` and `addCompletionTokens()` from extension

**Gemini**:
- Complex dual content streams (text + thinking)
- Cached content token calculation
- Multi-step tool call recursion
- Part-based content processing - state needs to track current part context

**OpenAI**:
- Currently uses NO instance properties for state
- All state in local variables within processStream method
- Decision: Add state object for consistency even though current approach works
- Benefit: Consistent pattern across all providers, easier debugging

**Parallel Execution Plan**:
- Agent 1: Group A (Groq, Mistral, DeepSeek) - 3 providers
- Agent 2: Group B (XAI, OpenRouter) - 2 providers
- Agent 3: Group C part 1 (Ollama, Gemini) - 2 providers
- Agent 4: Group C part 2 (OpenAI) - 1 provider

**Deliverables**:
- All 8 remaining providers refactored to use StreamState
- All provider tests passing
- Full test suite passing (850+ tests)

**Estimated Complexity**: High (8 providers, ~2,700 lines total, parallel execution)

---

### Phase 5: Cleanup and Verification

**Goal**: Remove old state management code, verify everything works

**Tasks**:
1. ✅ **Search for remaining direct state property access**
   - Grep for `$this->messageId` across all Stream handlers
   - Grep for `$this->streamStarted` patterns
   - Ensure all replaced with state object access

2. ✅ **Remove old resetState/resetTextState methods if fully replaced**
   - Check if any handlers still have their own reset methods
   - If state object handles all resets, remove handler methods

3. ✅ **Update PHPDoc blocks**
   - Remove property PHPDoc blocks for deleted properties
   - Add PHPDoc for state property if needed

4. ✅ **Run full test suite**
   - `composer test` - all 850+ tests must pass
   - `composer types` - zero errors
   - `composer format` - apply formatting

5. ✅ **Manual testing** (optional but recommended)
   - Test streaming with actual API calls to verify no regressions
   - Test multi-step tool calls
   - Test thinking/reasoning models

**Deliverables**:
- All old state management code removed
- Full test suite passing
- Zero static analysis errors
- Code formatted consistently

**Estimated Complexity**: Medium (verification and cleanup)

---

### Phase 6: Documentation

**Goal**: Document the new state management architecture

**Tasks**:
1. ✅ **Update `.claude/project.md`**
   - Document the new StreamState architecture
   - Explain when to extend StreamState vs use base class
   - Add examples of using state object in handlers

2. ✅ **Add code examples** in project.md
   - Show before/after refactor examples
   - Demonstrate fluent chaining patterns
   - Show how to add provider-specific state

3. ✅ **Update this plan.md**
   - Mark all phases complete
   - Add lessons learned section
   - Document any deviations from plan

**Deliverables**:
- Updated project documentation
- Code examples for future reference

**Estimated Complexity**: Low (documentation only)

---

## Testing Strategy

### Unit Testing

**StreamState Base Class Tests**:
- Test each fluent method returns `$this`
- Test each query method returns correct value
- Test accumulation methods (append, add)
- Test reset methods clear appropriate state
- Test helper methods (has*, shouldEmit*)

**Provider Extension Tests**:
- Test provider-specific properties and methods
- Test override behavior (reset methods)
- Test that base class functionality still works

### Integration Testing

**Provider Stream Tests**:
- Existing provider tests should all pass without modification
- Tests verify behavior, not implementation
- If tests break, investigate whether behavior changed or test needs update

**Full Suite**:
- All 850+ tests must pass after each phase
- No regressions allowed

### Manual Testing (Recommended)

- Test actual API calls with streaming
- Test multi-step tool execution
- Test thinking/reasoning models (Claude, DeepSeek, OpenAI o1)
- Test error scenarios

---

## Migration Checklist (Per Provider)

For each provider, complete these steps:

- [ ] Identify which state class to use (base vs extension)
- [ ] Add state property to handler class
- [ ] Initialize state in constructor or handle() method
- [ ] Refactor all property reads to state query methods
- [ ] Refactor all property writes to state fluent methods
- [ ] Replace reset method bodies with state resets
- [ ] Update all conditionals to use state helpers
- [ ] Remove old instance properties
- [ ] Run provider tests - all must pass
- [ ] Run full test suite - all must pass
- [ ] Static analysis passes
- [ ] Code formatting applied

---

## Risk Mitigation

### Risks and Mitigation Strategies

**Risk 1: Breaking existing tests**
- **Mitigation**: Run tests after each provider refactor
- **Recovery**: If tests break, investigate immediately - may indicate behavior change

**Risk 2: Performance regression**
- **Mitigation**: State object is lightweight, method calls inlined by opcache
- **Recovery**: If performance issues, profile and optimize hot paths

**Risk 3: Incomplete state tracking**
- **Mitigation**: Comprehensive unit tests for state object
- **Recovery**: Add missing state properties/methods if discovered

**Risk 4: Provider-specific edge cases**
- **Mitigation**: Thorough reading of each provider before refactor
- **Recovery**: Extend state class if unique requirements discovered

**Risk 5: Merge conflicts during parallel execution**
- **Mitigation**: Each agent works on different files (no overlap)
- **Recovery**: N/A - no file conflicts expected

---

## Success Criteria

**Phase 1**:
- ✅ Base StreamState class created with all methods
- ✅ Unit tests passing (100% coverage of state methods)
- ✅ Static analysis passes

**Phase 2**:
- ✅ Provider extensions created (Ollama, Anthropic)
- ✅ Extension tests passing

**Phase 3**:
- ✅ Anthropic Stream handler uses new state object
- ✅ Old unused StreamState deleted
- ✅ All Anthropic tests passing

**Phase 4**:
- ✅ All 8 remaining providers refactored
- ✅ All provider tests passing (850+ total)
- ✅ No state properties remain in handlers (except state object)

**Phase 5**:
- ✅ No old state management code remains
- ✅ Full test suite passes
- ✅ Static analysis clean
- ✅ Code formatted

**Phase 6**:
- ✅ Documentation updated
- ✅ Examples added

**Overall Success**:
- ✅ 100% of providers using fluent state object
- ✅ 0 test regressions
- ✅ 0 static analysis errors
- ✅ Consistent state management across all providers
- ✅ Easier to understand and maintain stream handlers

---

## Timeline Estimate

**Phase 1**: 2-3 hours (foundation work, testing)
**Phase 2**: 1 hour (simple extensions)
**Phase 3**: 3-4 hours (complex refactor, reference implementation)
**Phase 4**: 4-6 hours (8 providers in parallel)
**Phase 5**: 1-2 hours (cleanup, verification)
**Phase 6**: 1 hour (documentation)

**Total Estimated Time**: 12-17 hours

With parallel agent execution in Phase 4, actual time could be reduced to 8-12 hours.

---

## Implementation Notes

### Code Patterns

**Before (Anthropic example)**:
```php
protected string $messageId = '';
protected bool $streamStarted = false;
protected string $currentText = '';

// In method:
$this->messageId = $message['id'] ?? EventID::generate();
$this->streamStarted = true;
$this->currentText .= $text;

if (!$this->streamStarted) {
    yield new StreamStartEvent(/*...*/);
}

// Reset:
protected function resetState(): void
{
    $this->messageId = '';
    $this->streamStarted = false;
    $this->currentText = '';
    // ... 7 more lines
}
```

**After (using StreamState)**:
```php
protected AnthropicStreamState $state;

public function __construct(protected PendingRequest $client)
{
    $this->state = new AnthropicStreamState();
}

// In method:
$this->state
    ->setMessageId($message['id'] ?? EventID::generate())
    ->markStreamStarted()
    ->appendText($text);

if ($this->state->shouldEmitStreamStart()) {
    yield new StreamStartEvent(/*...*/);
}

// Reset (in handle method):
if ($depth === 0) {
    $this->state->reset();
}
```

### Fluent Chaining Benefits

**Multiple state updates**:
```php
// Before:
$this->messageId = EventID::generate();
$this->reasoningId = EventID::generate();
$this->streamStarted = true;
$this->textStarted = true;

// After:
$this->state
    ->setMessageId(EventID::generate())
    ->setReasoningId(EventID::generate())
    ->markStreamStarted()
    ->markTextStarted();
```

### Helper Method Benefits

**Before**:
```php
if (!$this->streamStarted) {
    $this->streamStarted = true;
    yield new StreamStartEvent(/*...*/);
}
```

**After**:
```php
if ($this->state->shouldEmitStreamStart()) {
    $this->state->markStreamStarted();
    yield new StreamStartEvent(/*...*/);
}
```

---

## Appendix A: State Property Mapping

### Common Properties (All Providers)

| Old Property | New State Method (Read) | New State Method (Write) |
|-------------|------------------------|-------------------------|
| `$messageId` | `$state->messageId()` | `$state->setMessageId($id)` |
| `$reasoningId` | `$state->reasoningId()` | `$state->setReasoningId($id)` |
| `$streamStarted` | `$state->hasStreamStarted()` | `$state->markStreamStarted()` |
| `$textStarted` | `$state->hasTextStarted()` | `$state->markTextStarted()` |
| `$thinkingStarted` | `$state->hasThinkingStarted()` | `$state->markThinkingStarted()` |

### Content Accumulators

| Old Property | New State Method (Read) | New State Method (Write) |
|-------------|------------------------|-------------------------|
| `$currentText` | `$state->currentText()` | `$state->appendText($text)` |
| `$currentThinking` | `$state->currentThinking()` | `$state->appendThinking($text)` |

### Collections

| Old Property | New State Method (Read) | New State Method (Write) |
|-------------|------------------------|-------------------------|
| `$toolCalls` | `$state->toolCalls()` | `$state->addToolCall($index, $data)` |
| `$citations` | `$state->citations()` | `$state->addCitation($citation)` |

### Metadata

| Old Property | New State Method (Read) | New State Method (Write) |
|-------------|------------------------|-------------------------|
| `$usage` | `$state->usage()` | `$state->setUsage($usage)` |
| `$finishReason` | `$state->finishReason()` | `$state->setFinishReason($reason)` |

---

## Appendix B: Provider-Specific Requirements

### Anthropic
- **Extension**: AnthropicStreamState
- **Additional Properties**: `currentThinkingSignature`, `currentBlockIndex`, `currentBlockType`
- **Unique Methods**: `appendThinkingSignature()`, `setBlockContext()`, `resetBlockContext()`
- **Complexity**: High (most complex state tracking)

### Ollama
- **Extension**: OllamaStreamState
- **Additional Properties**: `promptTokens`, `completionTokens`
- **Unique Methods**: `addPromptTokens()`, `addCompletionTokens()`
- **Complexity**: Medium (token accumulation)

### OpenAI
- **Extension**: None (base StreamState)
- **Current Approach**: All local variables in processStream()
- **Decision**: Add state object for consistency
- **Complexity**: Medium (conceptual shift from local to instance state)

### Gemini
- **Extension**: None (base StreamState)
- **Unique Features**: Dual content streams, cached content
- **Complexity**: High (complex content routing)

### Groq, Mistral, DeepSeek, XAI, OpenRouter
- **Extension**: None (base StreamState)
- **Complexity**: Low-Medium (straightforward refactor)

---

## Appendix C: Files to Create

**New Files**:
1. `/src/Streaming/StreamState.php` - Base state class
2. `/src/Providers/Ollama/ValueObjects/OllamaStreamState.php` - Ollama extension
3. `/src/Providers/Anthropic/ValueObjects/AnthropicStreamState.php` - Anthropic extension
4. `/tests/Unit/Streaming/StreamStateTest.php` - Base state tests
5. `/tests/Unit/Providers/Ollama/OllamaStreamStateTest.php` - Ollama extension tests
6. `/tests/Unit/Providers/Anthropic/AnthropicStreamStateTest.php` - Anthropic extension tests

**Files to Delete**:
1. `/src/Providers/Anthropic/ValueObjects/StreamState.php` - Old unused StreamState (286 lines)

**Files to Modify** (9 Stream handlers):
1. `/src/Providers/Anthropic/Handlers/Stream.php` (655 lines)
2. `/src/Providers/OpenAI/Handlers/Stream.php` (435 lines)
3. `/src/Providers/Gemini/Handlers/Stream.php` (445 lines)
4. `/src/Providers/Groq/Handlers/Stream.php` (316 lines)
5. `/src/Providers/Mistral/Handlers/Stream.php` (325 lines)
6. `/src/Providers/Ollama/Handlers/Stream.php` (322 lines)
7. `/src/Providers/XAI/Handlers/Stream.php` (365 lines)
8. `/src/Providers/DeepSeek/Handlers/Stream.php` (294 lines)
9. `/src/Providers/OpenRouter/Handlers/Stream.php` (439 lines)

**Total Lines to Refactor**: ~3,596 lines across 9 files

---

## Conclusion

This refactoring will significantly improve the maintainability and consistency of stream handlers across all providers. By introducing a unified `StreamState` object with a fluent interface, we eliminate scattered state management, reduce code duplication, and create a clear, self-documenting pattern for managing streaming state.

The plan prioritizes safety (testing after each phase), maintainability (consistent patterns), and extensibility (provider-specific state classes where needed). With parallel execution in Phase 4, the entire refactoring can be completed efficiently while maintaining high code quality standards.
