# Prism Harness

Harness adds persistent conversations, session settings, modes, tool authorization, approvals and subagents to Laravel applications using Prism.

## Installation

```bash
composer require particle-academy/prism-harness
php artisan vendor:publish --tag=prism-harness-config
php artisan migrate
php artisan harness:doctor
```

Settings live in `config/prism-harness.php`. Package migrations are loaded automatically; publish them with `prism-harness-migrations` only when you need local migration files. Configure provider credentials in Prism before sending a request.

## Sessions

Resolve a session for a persisted Eloquent participant and an application-authorized scope:

```php
use Prism\Harness\Facades\PrismHarness;

// $user is the authenticated, persisted application participant.
$session = PrismHarness::for($user)->session('support')
    ->usingProvider('anthropic')
    ->usingModel('claude-sonnet-5-5')
    ->usingMode('chat');

$result = $session->send('Explain how to update my billing address.');

echo $result->text();
$thread = $session->thread();
```

`AgentResponse` exposes `runId`, the core `response`, optional `parentRunId`/`rootRunId`, and `text()`, `correlation()`, `isChildRun()`, `awaitingApproval()` and `pendingApprovals()`. Inspect `$result->response->finishReason` for truncation or a step ending in tool calls.

`usingProvider()`, `usingModel()`, `usingMode()` and `usingMaxSteps()` persist session choices. A per-run `$toolNames` argument narrows the mode's toolset; it cannot add a tool outside that mode.

`newConversation()` retires the current thread and creates another at the same participant/scope. `forget()` clears ephemeral settings without deleting the conversation. `state()`, `key()` and `scope()` expose session metadata. Capability state uses `capability($name)`, `usingCapability($name, $state)` and `forgetCapability($name)`.

## Modes, tools and skills

Define modes under `agent.modes` in the existing configuration:

```php
'agent' => [
    'provider' => 'anthropic',
    'model' => 'claude-sonnet-5-5',
    'default' => 'support',
    'authorize_tools' => true,
    'modes' => [
        'support' => [
            'system_prompt' => 'Help with support requests using the offered tools.',
            'tools' => ['lookup_order'],
            'skills' => [],
            'max_steps' => 4,
            'requires_approval' => [],
            'provider_options' => ['effort' => 'medium'],
        ],
    ],
],
```

Merge these settings with your existing `agent` configuration. Mode keys are `system_prompt`, `tools`, `skills`, `max_steps`, `requires_approval`, `provider_options` and `subagents`. Select provider/model with session setters or `agent.provider`/`agent.model`; placing them in a mode entry does not select them.

Register application-owned Prism tools in a service provider's `boot()` method:

```php
use Prism\Harness\Tools\ToolRegistry;

// $lookupOrder is a Prism Tool whose handler authorizes access to each order.
app(ToolRegistry::class)->register($lookupOrder);
```

The registry also accepts `registerMany()`, `registerFactory($name, Closure $factory)` and `registerProvider(Closure $provider)`. Session-aware factories/providers receive the session.

Enable `agent.authorize_tools` and define `harness.tool` to filter tools before offering them. The optional `harness.tool.call` Gate checks invocation arguments. Both receive the participant as the Gate user, then `$session`, `$tool`, and, for call authorization, `$arguments`. Keep data-level authorization in handlers too. Defining the offer Gate while disabling authorization raises `UnsafeAuthorizationConfiguration`.

Skill names load `SKILL.md` from the package's `resources/skills/<name>/` directory. Selected skills extend the system prompt and make `skill_read` available for referenced resources; a skill is not a file in the application's Workspace.

## Approvals

A tool's `requiresApproval()` or a mode's `requires_approval` list can pause a run. A list containing `*` covers every offered tool. Store the pending requests and present them to an authorized user:

```php
if ($result->awaitingApproval()) {
    $pending = $result->pendingApprovals();
    // Persist the pending IDs and display the exact proposed calls.
}
```

After authenticating and authorizing the decision, reload the participant's session and call `approve($requestOrId)` or `deny($requestOrId, reason: $reason)`. For multiple pending calls, submit decisions together; resuming after just one leaves unanswered calls denied:

```php
use Prism\Prism\ValueObjects\ToolApprovalResponse;

// $authorizedDecisions contains every decision validated by your application.
$decisions = array_map(
    fn (array $decision) => new ToolApprovalResponse(
        approvalId: $decision['approval_id'],
        approved: $decision['approved'],
        reason: $decision['reason'] ?? null,
    ),
    $authorizedDecisions,
);
$result = $session->decide($decisions);
```

Do not approve from model output or unvalidated request values. Decisions continue the stored conversation; do not resend the original prompt.

## Streaming and structured output

`stream($prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null)` yields core stream events. Consume the generator and handle terminal failures; an early delta is not proof of successful completion.

`sendStructured($prompt, Schema $schema, ...)` returns `StructuredAgentResponse`, with `structured()`, `text()` and run correlation. Invalid output raises `StructuredSchemaViolation`. Your application's domain rules still need validation. See the core [schemas](/core-concepts/schemas) and [streaming](/core-concepts/streaming-output) guides.

## Threads and locks

To use persistent history without the agent runtime, resolve `Prism\Harness\Models\Thread::forParticipant($user, $scope)`, supply it to core `withThread()`, then call `$thread->record($response->messages)`. Preserve the complete messages, including tool and provider replay data.

`$session->lock($callback, ttlSeconds: 10, waitSeconds: 5)` runs the callback under the session store's lock and raises `SessionLocked` if it cannot acquire it. Agent runs have their own `agent.lock_ttl` and `agent.lock_wait`. Choose expiry bounds for the workload and avoid wrapping a run in an unrelated nested lock.

## Subagents, tasks and integrations

A mode's `subagents` map names child configurations with `description`, `mode`, optional `scope`, `max_steps`, `max_cost_usd`, `max_seconds` and `max_output_tokens_per_call`. The child mode must exist. `RunBudget` tracks these bounds across nested runs; cost enforcement needs configured pricing and per-call output bounds. Inspect child outcomes and the core finish reason instead of treating a budget stop as a complete answer.

`PrismHarness::tasks($list)` and `$session->tasks()` return durable task sources. `add($instruction, $id)` creates work; `claim($worker, leaseSeconds: ...)` leases it, and `extendLease($task, $worker, $ledger, $budget, $leaseSeconds)` renews an owned lease within its run budget. Configure `tasks.lease_seconds` and `tasks.lock_wait`; lease loss must be handled before recording completion.

Run events are `RunStarted`, `RunFinished` and `RunFailed` under `Prism\Harness\Events`. Voice integration is available through `VoiceExchange`. Optional `Flow\PrismLlmClient` and `Flow\HarnessToolInvoker` adapters require the corresponding Fancy Flow package; they are not installed by core. See the [package guide](https://github.com/Particle-Academy/prism-harness#usage) for these integrations and their requirements.

## Configuration and failures

| Settings | Purpose |
|---|---|
| `default_scope`, `ephemeral_ttl` | Session addressing and ephemeral-state lifetime; supply an integer TTL in PHP configuration |
| `stores.ephemeral`, `stores.durable` | Both default to `database`; configure Redis explicitly when used |
| `drivers.database.*`, `drivers.redis.*` | Connection/table or Redis prefix and durability declaration |
| `context.*` | Recent-turn retention, recall budget and summarization |
| `agent.provider`, `agent.model`, `agent.default`, `agent.modes` | Default runtime and modes |
| `agent.lock_ttl`, `agent.lock_wait` | Run-lock expiry and acquisition wait |
| `agent.authorize_tools`, `agent.max_steps_ceiling` | Gate enforcement and ceiling on session step overrides |
| `agent.max_output_tokens_per_call`, `pricing`, `cost_observer` | Per-call output bounds, pricing and observed cost |
| `tasks.lease_seconds`, `tasks.lock_wait` | Durable task leasing |

A durable slot refuses volatile stores with `UnsafeStateConfiguration`. Redis reports volatile unless `drivers.redis.durable` is explicitly true; set it only for infrastructure you operate durably.

Exceptions under `Prism\Harness\Exceptions` include `SessionLocked`, `RunNotPermitted`, `ToolNotAvailable`, `CallRefused`, `StructuredSchemaViolation`, `UnsafeStateConfiguration`, `UnsafeAuthorizationConfiguration`, `UnsafeAudioSource`, `UnacceptableAttachment`, `UnmappableContent`, `UnmappableTask`, `InvalidTaskIdentifier`, `InvalidTaskLease`, `InvalidTaskOutcome`, `LeaseNotExtendable` and `TaskNotReleasable`. Core provider failures can also propagate.

`php artisan harness:doctor` checks stores, authorization, mode definitions, tool references and skills. Resolve its failures before running agents.
