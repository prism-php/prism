# Handling Rate Limits

Hitting issues with rate limits? We've got you covered!

In this guide we will look at handling:
- situations where you actually hit a rate limit (i.e. HTTP 429); and
- dynamic rate limiting (figuring out when you can make your next request, from a successful request).

## Provider support

Prism throws a `PrismRateLimitedException` for all providers other than DeepSeek (which does not have rate limits).

Prism provides an array of `ProviderRateLimit` value objects on the exception and on meta for all providers other than Gemini, xAI and VoyageAI - as they do not provide the necessary headers to do so.

## The ProviderRateLimit value object

Throughout this guide, we'll talk about the `ProviderRateLimit` value object.

Each `ProviderRateLimit` has four properties:
- name - the name given to that rate limit by the provider - e.g. "input-tokens"
- limit - the current limit set on your API key by the provider - e.g. for input-tokens, perhaps 80000
- remaining - how many you have left - e.g. for input-tokens if you have used 30000 out of your 80000 limit - this will be 50000
- resetsAt - a Carbon instance with the date and time at which remaining will reset to limit

## Handling a rate limit hit

Prism throws a `PrismRateLimitedException` when you hit a rate limit.

You can catch that exception, gracefully fail and inspect the `rateLimits` property which contains an array of `ProviderRateLimit`s. 

```php
use Prism\Prism\Prism;
use Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
        ->withPrompt('Hello world!')
        ->generate();
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

In a simple world, they'd only be one rate limit. 

However most providers implement various rate limits (e.g. request, input tokens, output tokens, etc.) and provide you with information on all of them on all requests, regardless of which you have hit.

For simple rate limits like "requests", the `remaining` property on `ProviderRateLimit` will be 0 if you have hit it. These are easy to find:

```php 
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Illuminate\Support\Arr;

try {
    // Your request
}
catch (PrismRateLimitedException $e) {
    $hit_limit = Arr::first($e->rateLimits, fn(ProviderRateLimit $rate_limit) => $rate_limit->remaining === 0);
}
```

For less simple rate limits like input tokens, the `remaining` property may not be zero. For instance, if you have 5,000 input tokens remaining and submit a request requiring 6,000 tokens, you'll be rate limited but remaining will still show 5,000.

Here, you may need to implement some logic to approximate how many tokens your request will use before sending it, and then test against that:

```php 
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Illuminate\Support\Arr;

try {
    // Your request
}
catch (PrismRateLimitedException $e) {
    $input_token_limit = Arr::first($e->rateLimits, fn(ProviderRateLimit $rate_limit) => $rate_limit->name === 'input-tokens');

    if ($input_token_limit < $your_token_estimate) {
        // Handle
    }
}
```

To help with approximating input token usage, we plan to implement Anthopic's token counting endpoint in a future release. 

For providers that don't have a token counting endpoint, you could either roll your own token counter or use something like [tiktoken](https://github.com/openai/tiktoken) if you are comfortable calling out to Python.

Once you know which rate limit you have hit, you'll want to ensure your app does not continue making requests until after the `ProviderRateLimit` `resetsAt` property. 

If you aren't sure where to start with that, check out the [What should you do with rate limit information](#what-should-you-do-with-rate-limit-information) section below.

## Dynamic rate limiting

Prism adds the same rate limit information to every successful request:

```php
use Prism\Prism\Prism;
use Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderRateLimit;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Hello world!')
    ->generate();
    
/** @var ProviderRateLimit $rate_limit */ 
foreach ($response->meta->rateLimits as $rate_limit) {
    // Handle
}

```

Armed with that information, you'll probably want to [update your app's rate limiter(s)](#what-should-you-do-with-rate-limit-information).

## What should you do with rate limit information?

You'll likely want to implement a rate limiter within your app. Thankfully Laravel, as always, makes this very easy!

You should take a look at the [rate limiting](https://laravel.com/docs/11.x/rate-limiting) docs, and if you are firing requests from your queue, check out the [job middleware](https://laravel.com/docs/11.x/queues#job-middleware) docs.

You should implement a rate limiter / job middleware for each of the provider rate limits your application typically hits. 
