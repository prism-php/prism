# Prism Memory

Store text and conversation-derived records by owner and scope, then retrieve related context with embeddings and a token budget.

## Installation

```bash
composer require particle-academy/prism-memory
php artisan vendor:publish --tag=memory-config
php artisan migrate
```

Settings live in `config/memory.php`. Configure the embedding provider's credentials in Prism. The default store is a database table; its portable migration loads automatically. Publish `memory-migrations` only when you need local migration files.

For PostgreSQL with pgvector, configure the `pgvector` driver, choose dimensions matching the embedding model, publish `memory-pgvector-migrations` and run migrations. The database role must be allowed to install the `vector` extension.

## Remembering and recalling

The owner can be an Eloquent model or a stable string. Authorize the owner and scope in your application before resolving them:

```php
use Prism\Memory\Facades\PrismMemory;
use Prism\Prism\Facades\Prism;

// $user is the authenticated owner. Inline embedding allows immediate recall.
$memory = PrismMemory::for($user, scope: 'support')->synchronously();
$ids = $memory->remember('The customer prefers email for billing questions.');

$relevant = $memory->recall('billing contact preference', budget: 1500);

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withSystemPrompt('Use recalled context as reference material.')
    ->withSystemPrompt($relevant->asContext())
    ->withPrompt('How should we contact this customer about billing?')
    ->asText();
```

`remember(iterable|Message|string $subject, array $metadata = [], ?Carbon $occurredAt = null): array` accepts a string, one core message, or iterable messages such as `$response->messages`. It returns record IDs. Metadata values are scalar or null; role and record-kind metadata are populated by the package. Tool results are stored by default.

Embedding normally runs through the `EmbedMemories` queue job after records are written. A record cannot be recalled before its vector is stored. Run your configured queue, inspect `pending()`, or call `embedPending(?int $batch = null)` to process pending records. `synchronously()` returns a copy that embeds new records inline. A synchronous Laravel queue also runs dispatched jobs inline.

Repeated content/role/tool identity within a collection replaces its existing record. Changing the embedding model or dimensions changes the embedding space; migrate or re-embed data rather than mixing incompatible vectors.

## Recall options

```php
use Prism\Memory\ValueObjects\Weighting;

$relevant = $memory->recall(
    query: 'billing address',
    limit: 8,
    budget: 1500,
    weighting: new Weighting(relevance: 0.7, recency: 0.3),
    minScore: 0.2,
    filter: ['role' => 'user'],
);
```

The full signature is `recall(string $query, ?int $limit = null, ?int $budget = null, ?Weighting $weighting = null, ?float $minScore = null, array $filter = []): Recollection`. A budget selects records within an estimated token count; it is not an exact model-token or billing bound. The candidate search uses the configured overfetch limit.

`Weighting` accepts `relevance`, `recency` and `halfLifeSeconds`; weights must be nonnegative with a nonzero total, and half-life must be positive. The defaults use relevance alone. `filter` matches metadata, including lists of accepted scalar values.

## Recollection

| Member | Use |
|---|---|
| `asContext()` | One text block for a system prompt |
| `asSystemMessage()` | One core SystemMessage |
| `asMessages()` | Empty array or one SystemMessage |
| `all()`, iteration | Chronological records with stable ID ordering for time ties |
| `ranked()` | Records sorted by score |
| `estimatedTokens` | Token estimate for the selected records |
| `digest()` | SHA-256 of the rendered context |
| `isEmpty()`, `count()` | Inspect the result |

Each recalled record includes content, kind, role, similarity, recency, combined score, occurrence time and metadata. Use `withSystemPrompt($relevant->asContext())` alongside a new prompt. A nonempty explicit `withMessages()` list cannot be combined with `withPrompt()`; append the user message explicitly when using a message list.

Stored content can include untrusted instructions. Retain handler authorization and tool restrictions when using recalled material.

## Forgetting and retention

```php
$deleted = $memory->forget($ids);    // Specific records in this collection.
$total = $memory->count();
```

`forget()` with no IDs, or an empty array, deletes the entire owner/scope collection. `forgetBefore(DateTimeInterface $before)` deletes records by occurrence time. Both return the deletion count; authorize destructive operations before calling them.

`retention` is disabled by default. Configured expiry is checked during retrieval; expiry does not replace a deletion policy for stored rows.

## Configuration

| Key | Default / purpose |
|---|---|
| `default_scope`, `remember_tool_results` | `default`, true |
| `embeddings.provider`, `embeddings.model`, `embeddings.batch` | `openai`, `text-embedding-3-small`, 96 |
| `recall.limit`, `recall.overfetch`, `recall.min_score` | 8, 8, null |
| `recall.relevance`, `recall.recency`, `recall.half_life` | 1.0, 0.0, 604800 seconds |
| `recall.query_cache` | 300 seconds for repeated query embeddings |
| `retention` | null; optional lifetime in seconds |
| `store`, `require_durable` | `database`, true |
| `drivers.database.connection`, `table` | Database connection and `memory_vectors` |
| `drivers.database.index` | `strategy`, `bits`, `candidates`, `seed`, `exact_scan_limit` |
| `drivers.pgvector.*` | Connection/table, `dimensions` (1536), `ef_search` (100) |

The published file maps these to `MEMORY_*` variables, including `MEMORY_EMBEDDINGS_PROVIDER/MODEL/BATCH`, `MEMORY_RECALL_*`, `MEMORY_RETENTION`, `MEMORY_STORE`, `MEMORY_REQUIRE_DURABLE`, `MEMORY_DB_CONNECTION`, `MEMORY_INDEX_*` and `MEMORY_PGVECTOR_*`. Changing database signature bits or seed requires re-indexing stored signatures.

## Harness integration and errors

With Harness installed, `Prism\Memory\Harness\MemoryContextRecall` implements its context recall contract, and `MemoryEvictionSink` stores evicted messages. Construct both with the same memory manager and authorized owner; use the same Harness-supplied scope for storage and retrieval. Their optional configuration does not install Harness automatically.

Exceptions under `Prism\Memory\Exceptions` are `EmbeddingFailed`, `EmbeddingSpaceMismatch`, `InvalidVector`, `UnsafeMemoryConfiguration` and `UnstorableMemory`. Handle queue failures and embedding/provider errors rather than treating missing vectors as successful recall. An application-specific vector backend implements `Contracts\VectorStore` and registers it through the store manager's `extend()` method.

See the [package guide](https://github.com/Particle-Academy/prism-memory) for custom stores and integration setup.
