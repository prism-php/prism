# Handling Rate Limits

Handle rate-limit errors and use provider quota information to schedule requests.

## Provider support

Prism maps rate-limit responses to `PrismRateLimitedException` where the provider
integration implements that mapping. DeepSeek currently uses the generic
`PrismException` path; this does not imply that its API has no rate limits.

The exception's `rateLimits` array and response metadata contain
`ProviderRateLimit` objects when the integration can extract quota details.
OpenAI parses rate-limit headers; Gemini can extract quota details from an
error payload. An array can be empty, so handle the exception even when no
quota buckets are available.

## The ProviderRateLimit value object

Throughout this guide, we'll talk about the `ProviderRateLimit` value object.

Each `ProviderRateLimit` has four properties:
- name - the bucket identifier assigned by the integration - e.g. "input-tokens"
- limit - the current limit set on your API key by the provider - e.g. for input-tokens, perhaps 80000
- remaining - how many you have left - e.g. for input-tokens if you have used 30000 out of your 80000 limit - this will be 50000
- resetsAt - a Carbon instance with the reset or retry time parsed from the provider response

`limit`, `remaining` and `resetsAt` can be `null` when the provider does not
report them. Check for a reset time before scheduling a retry from that value.

## Handling a rate limit hit

For integrations with the mapping described above, catch `PrismRateLimitedException` when a request is rate limited.

You can catch that exception, gracefully fail and inspect the `rateLimits` property which contains an array of `ProviderRateLimit`s. 

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
        ->withPrompt('Hello world!')
        ->asText();
}
catch (PrismRateLimitedException $e) {
    /** @var ProviderRateLimit $rate_limit */ 
    foreach ($e->rateLimits as $rate_limit) {
        // Loop through rate limits...
    }
    
    // Log, fail gracefully, etc.
}
```

### Figuring out which rate limit you have hit

Providers can enforce separate limits for requests, input tokens and output tokens. A response may report several limits, including ones that have not been exhausted.

When the provider reports an exhausted bucket with `remaining` set to 0, you can find it as follows. A missing value does not mean that capacity is available:

```php 
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    // Your request
}
catch (PrismRateLimitedException $e) {
    $hit_limit = Arr::first($e->rateLimits, fn(ProviderRateLimit $rate_limit) => $rate_limit->remaining === 0);
}
```

An input-token bucket can have capacity remaining that is insufficient for your
next request. For example, a reported `remaining` value of 5,000 is less than an
estimated request size of 6,000 tokens. Do not rely only on a zero check.

Here, you may need to implement some logic to approximate how many tokens your request will use before sending it, and then test against that:

```php 
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Illuminate\Support\Arr;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    // Your request
}
catch (PrismRateLimitedException $e) {
    $input_token_limit = Arr::first($e->rateLimits, fn(ProviderRateLimit $rate_limit) => $rate_limit->name === 'input-tokens');

    // $your_token_estimate is calculated by your application.
    if ($input_token_limit?->remaining !== null && $input_token_limit->remaining < $your_token_estimate) {
        // Handle
    }
}
```

Estimate token use with a tokenizer appropriate for your model when the provider does not expose a token-counting endpoint.

When `resetsAt` is available, use it to delay further requests for that bucket. Otherwise, use the provider's retry guidance or your application's backoff policy.

If you aren't sure where to start with that, check out the [What should you do with rate limit information](#what-should-you-do-with-rate-limit-information) section below.

## Dynamic rate limiting

Supported integrations can also include quota information on successful
responses. The array is empty when those details are unavailable:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderRateLimit;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Hello world!')
    ->asText();
    
/** @var ProviderRateLimit $rate_limit */ 
foreach ($response->meta->rateLimits as $rate_limit) {
    // Handle
}

```

Armed with that information, you'll probably want to [update your app's rate limiter(s)](#what-should-you-do-with-rate-limit-information).

## What should you do with rate limit information?

Apply rate limits in your application or queue to delay requests until capacity is available.

You should take a look at the [rate limiting](https://laravel.com/docs/11.x/rate-limiting) docs, and if you are firing requests from your queue, check out the [job middleware](https://laravel.com/docs/11.x/queues#job-middleware) docs.

You should implement a rate limiter / job middleware for each of the provider rate limits your application typically hits.
