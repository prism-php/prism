<script setup>
import ExceptionSupport from '../components/ExceptionSupport.vue'
</script>

# Error handling

By default, Prism throws a `PrismException` for Prism errors, or a `PrismServerException` for Prism Server errors.

Catch specific exception types to implement retries, failover or application error messages.

## Provider agnostic exceptions

- `PrismStructuredDecodingException` where a provider has returned invalid JSON for a structured request.

## Exceptions based on provider feedback

Provider feedback can raise the following exceptions:

- `PrismRateLimitedException` where you have hit a rate limit or quota (see [Handling rate limits](/advanced/rate-limits.html) for more info).
- `PrismProviderOverloadedException` where the provider is unable to fulfil your request due to capacity issues.
- `PrismRequestTooLargeException` where your request is too large.
- `PrismRunException` where a Perplexity run is incomplete, failed or cancelled. See [Perplexity run failures](/providers/perplexity#failures-arrive-as-http-200) for outcome codes and explicit access to potentially sensitive diagnostics.
- `PrismRefusalException` where OpenAI Chat Completions returns a non-empty refusal. Its `code()` is `response_refused`; see [Chat Completions refusals](/providers/openai#chat-completions-refusals) for text, structured and streaming behavior.

Exception mapping varies by provider. Keep a fallback handler for `PrismException` when a more specific exception is unavailable.

The table below covers rate-limit, overload and request-size mappings.

<ExceptionSupport />
