<script setup>
import ExceptionSupport from '../components/ExceptionSupport.vue'
</script>

# Error handling

Catch specific exception types for application decisions, with a fallback for `PrismException`. Transport failures and invalid application inputs can use other exception types.

## Exception types

The following classes live under `Prism\Prism\Exceptions`. All extend `PrismException` except `PrismServerException`, which extends PHP's `Exception` directly.

| Exception | When to handle it |
|---|---|
| `PrismException` | Invalid request state, unsupported actions, tool failures or generic provider failures |
| `PrismStructuredDecodingException` | A structured response cannot be decoded; its message includes the response text |
| `PrismStreamDecodeException` | Stream decoding fails; inspect the previous exception privately |
| `PrismUrlRefused` | An explicit guarded media fetch refuses a URL, redirect or size |
| `PrismRateLimitedException` | A provider maps rate/quota exhaustion; exposes `rateLimits` and optional `retryAfter` seconds |
| `PrismProviderOverloadedException` | A provider maps temporary capacity exhaustion |
| `PrismRequestTooLargeException` | A provider maps an oversized request |
| `PrismBatchRequestLimitExceededException` | A local batch exceeds the integration's request count limit |
| `PrismBatchPayloadSizeExceededException` | A local batch exceeds its configured payload limit |
| `PrismRunException` | A Perplexity run is incomplete, failed or cancelled, even with HTTP 200 |
| `PrismRefusalException` | OpenAI Chat Completions supplies a nonempty refusal |
| `PrismServerException` | Server model resolution or message mapping fails |

A refusal can also be represented by a response's `FinishReason::Refusal`; it does not always throw. Inspect [finish reasons](/core-concepts/text-generation#why-generation-stopped) before treating returned text as a completed result. Streamed deltas are provisional until the stream completes successfully.

## Handling failures

This example records a transport failure without copying potentially sensitive exception messages:

```php
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;

try {
    $response = Prism::text()
        ->using('anthropic', 'claude-sonnet-5-5')
        ->withPrompt('Explain database indexes.')
        ->asText();
} catch (PrismRateLimitedException $error) {
    // Your queue can reschedule using $error->retryAfter or rateLimits.
    throw $error;
} catch (PrismException $error) {
    Log::warning('Provider request failed', [
        'exception_type' => $error::class,
        'http_status' => $error->httpStatus,
    ]);
    throw $error;
} catch (ConnectionException $error) {
    Log::warning('Provider connection failed');
    throw $error;
}
```

Some audio operations and input validation can throw generic exceptions, `InvalidArgumentException` or native PHP errors. Keep a suitable application-level `Throwable` handler; do not assume every possible failure is a `PrismException`. Tool handlers normally return `ToolError` rather than throwing; see [tool error handling](/core-concepts/tools-function-calling#error-handling).

## Diagnostics and codes

`PrismException::$httpStatus` and `$responseBody` are nullable public fields. Integrations populate them selectively; `getCode()` is not a reliable cross-provider HTTP-status accessor. Exception messages, chained exceptions and response bodies can contain prompts, partial output, credentials or provider diagnostics. Do not return them directly to a browser or log them indiscriminately.

`PrismUrlRefused::code()` returns a stable string:

| Code | Meaning |
|---|---|
| `scheme_not_allowed` | Missing/invalid absolute HTTP(S) URL or a refused scheme |
| `private_address_refused` | Private, loopback, reserved or invalid address |
| `host_did_not_resolve` | No public address could be resolved |
| `redirect_refused` | A redirected destination was refused |
| `too_many_redirects` | The redirect limit was exceeded |
| `media_too_large` | Declared or streamed content exceeded the byte limit |

Do not substitute the unguarded fetch after a refusal. See [remote media](/input-modalities/images#fetching-remote-media) for limits and DNS-boundary details.

`PrismRefusalException::code()` returns `response_refused`; `refusal()` exposes the provider's refusal text explicitly. `PrismRunException::code()` identifies `run_incomplete`, `run_failed` or `run_cancelled`. Its accessors include `status()`, `runId()`, `incompleteReason()`, `incompleteDetails()`, `output()`, `citations()`, `usage()` and `usageDetails()`. These diagnostics can contain partial answers and sensitive content. See [Perplexity run failures](/providers/perplexity#failures-arrive-as-http-200).

## Provider mappings

Exception mapping varies by integration. The table covers rate-limit, overload and request-size mappings; keep a fallback for generic failures.

<ExceptionSupport />

See [rate limits](/advanced/rate-limits) for quota buckets and retry guidance, and [Batch](/core-concepts/batch#job-metadata-and-limits) for local submission limits.
