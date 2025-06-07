# Prism HTTP Client Implementation Guide

## Overview

This document outlines the implementation plan for creating a comprehensive HTTP client library for Prism that mirrors Laravel's HTTP client API exactly while adding Prism-specific enhancements.

## Research Summary

### Current State Analysis
- **Laravel's HTTP Client**: Already uses Guzzle as the underlying HTTP transport
- **Prism Providers**: Currently use `Illuminate\Http\Client\PendingRequest` via `Http` facade
- **Integration Pattern**: Each provider implements a `client()` method returning configured instances
- **Namespace**: All new classes will use `Prism\Prism\Http` namespace

### Key Requirements
1. **API Compatibility**: Exact same method signatures and behavior as Laravel's HTTP client
2. **Guzzle Foundation**: Leverage Guzzle for HTTP transport (same as Laravel)
3. **Prism Integration**: Seamless integration with existing provider patterns
4. **Enhanced Features**: Add Prism-specific error handling and provider hooks
5. **Testing Support**: Full compatibility with Laravel's HTTP testing features

## Implementation Architecture

### Core Classes Structure

```
src/Http/
├── Factory.php                    # Main factory class (like Http facade)
├── PendingRequest.php            # Request builder and configuration
├── Response.php                  # Response wrapper with Prism enhancements  
├── Pool.php                      # Concurrent requests support
├── Concerns/
│   ├── InteractsWithProviders.php   # Prism provider integration hooks
│   ├── HandlesErrors.php           # Enhanced error handling
│   └── MakesRequests.php           # Core HTTP request logic
├── Exceptions/
│   ├── HttpException.php           # Base HTTP exception
│   ├── ProviderHttpException.php   # Provider-specific HTTP errors
│   └── RateLimitException.php      # Rate limiting errors
└── Testing/
    ├── HttpFake.php               # Testing fake implementation
    ├── ResponseFake.php           # Fake response builder
    └── PoolFake.php               # Fake pool for concurrent tests
```

### Facade Integration

```
src/Facades/
└── Http.php                      # Facade for easy access
```

## Detailed Implementation Plan

### 1. Factory Class (`src/Http/Factory.php`)

**Purpose**: Main entry point for creating HTTP requests, equivalent to Laravel's `Http` facade.

**Key Methods to Implement**:
```php
// Basic HTTP verbs
public function get(string $url, array $query = []): Response
public function post(string $url, array $data = []): Response  
public function put(string $url, array $data = []): Response
public function patch(string $url, array $data = []): Response
public function delete(string $url, array $data = []): Response
public function head(string $url, array $query = []): Response

// Request configuration
public function withHeaders(array $headers): PendingRequest
public function withUserAgent(string $userAgent): PendingRequest
public function withBasicAuth(string $username, string $password): PendingRequest
public function withDigestAuth(string $username, string $password): PendingRequest
public function withToken(string $token, string $type = 'Bearer'): PendingRequest
public function accept(string $contentType): PendingRequest
public function acceptJson(): PendingRequest
public function asForm(): PendingRequest
public function asJson(): PendingRequest
public function asMultipart(): PendingRequest
public function bodyFormat(string $format): PendingRequest
public function withBody(string $content, string $contentType): PendingRequest
public function attach(string $name, mixed $contents, string $filename = null, array $headers = []): PendingRequest

// Configuration  
public function withOptions(array $options): PendingRequest
public function withMiddleware(callable $middleware): PendingRequest
public function withRequestMiddleware(callable $middleware): PendingRequest
public function withResponseMiddleware(callable $middleware): PendingRequest
public function withoutRedirecting(): PendingRequest
public function withoutVerifying(): PendingRequest
public function sink(string $to): PendingRequest
public function timeout(int $seconds): PendingRequest
public function connectTimeout(int $seconds): PendingRequest
public function retry(int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true): PendingRequest
public function withCookies(array $cookies, string $domain): PendingRequest
public function maxRedirects(int $max): PendingRequest

// Advanced features
public function baseUrl(string $url): PendingRequest
public function withUrlParameters(array $parameters = []): PendingRequest
public function pool(callable $callback): array
public function async(): PendingRequest

// Testing
public function fake(callable|array $callback = null): Factory
public function assertSent(callable $callback): void
public function assertNotSent(callable $callback): void  
public function assertNothingSent(): void
public function assertSentCount(int $count): void
public function assertSequencesAreEmpty(): void
public function preventStrayRequests(bool $prevent = true): Factory
public function allowStrayRequests(): Factory
public function recordRequestResponsePair(Request $request, Response $response): void

// Prism-specific enhancements
public function withProvider(string $provider): PendingRequest
public function withRateLimit(int $requestsPerMinute): PendingRequest
public function withPrismOptions(array $options): PendingRequest
```

### 2. PendingRequest Class (`src/Http/PendingRequest.php`)

**Purpose**: Fluent request builder that handles configuration before sending requests.

**Key Responsibilities**:
- Request configuration and building
- Header management
- Authentication handling  
- Body format and content management
- Middleware application
- Request execution

**Implementation Details**:
```php
class PendingRequest
{
    use Conditionable, Macroable;
    
    protected Factory $factory;
    protected Client $client;
    protected string $baseUrl = '';
    protected array $urlParameters = [];
    protected string $bodyFormat;
    protected StreamInterface|string $pendingBody;
    protected array $pendingFiles = [];
    protected array $cookies;
    protected array $options = [];
    protected array $middleware = [];
    protected int $tries = 1;
    protected Closure|int $retryDelay = 0;
    protected ?callable $retryWhenCallback = null;
    protected bool $retryThrow = true;
    
    // Prism-specific properties
    protected string $prismProvider;
    protected array $prismOptions = [];
    protected ?int $rateLimit = null;
}
```

### 3. Response Class (`src/Http/Response.php`)

**Purpose**: Wrapper around Guzzle/PSR-7 responses with Laravel-compatible API and Prism enhancements.

**Key Methods**:
```php
// Content access
public function body(): string
public function json(string $key = null, mixed $default = null): mixed
public function object(): object
public function collect(string $key = null): Collection
public function stream(): StreamInterface

// Status and metadata  
public function status(): int
public function reason(): string
public function successful(): bool
public function redirect(): bool  
public function failed(): bool
public function clientError(): bool
public function serverError(): bool
public function ok(): bool
public function created(): bool
public function accepted(): bool
public function noContent(): bool
public function notFound(): bool
public function forbidden(): bool
public function unauthorized(): bool
public function unprocessableEntity(): bool
public function tooManyRequests(): bool

// Headers and cookies
public function header(string $header): string
public function headers(): array
public function hasHeader(string $header): bool
public function cookies(): array
public function effectiveUri(): UriInterface

// Error handling
public function throw(callable $callback = null): Response
public function throwIf(bool|callable $condition, callable $throwCallback = null): Response
public function throwIfStatus(callable|int $statusCode): Response
public function throwUnless(bool|callable $condition, callable $throwCallback = null): Response
public function throwIfClientError(callable $callback = null): Response  
public function throwIfServerError(callable $callback = null): Response

// Prism-specific enhancements
public function getProvider(): ?string
public function getRateLimit(): ?ProviderRateLimit
public function getUsage(): ?Usage
public function getPrismMeta(): ?Meta
```

### 4. Pool Class (`src/Http/Pool.php`)

**Purpose**: Handle concurrent HTTP requests efficiently.

**Key Methods**:
```php
public function as(string $key): Pool
public function withOptions(array $options): Pool  
public function pool(callable $callback): array

// Usage example:
$responses = Http::pool(fn (Pool $pool) => [
    $pool->as('first')->get('https://api.example.com/users'),
    $pool->as('second')->get('https://api.example.com/posts'),
]);
```

### 5. Enhanced Error Handling

**Custom Exceptions**:
```php
// Base HTTP exception
class HttpException extends PrismException
{
    public function __construct(
        public readonly Response $response,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

// Provider-specific HTTP errors
class ProviderHttpException extends HttpException
{
    public function __construct(
        public readonly string $provider,
        Response $response,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($response, $message, $code, $previous);
    }
}

// Rate limiting errors
class RateLimitException extends ProviderHttpException
{
    public function __construct(
        string $provider,
        Response $response,
        public readonly ?int $retryAfter = null,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($provider, $response, $message, $code, $previous);
    }
}
```

### 6. Testing Support

**Fake Implementation** (`src/Http/Testing/HttpFake.php`):
```php
class HttpFake extends Factory
{
    protected array $stubCallbacks = [];
    protected bool $preventStrayRequests = false;
    protected array $recorded = [];
    
    public function fake(callable|array $callback = null): Factory
    public function sequence(array $responses = []): ResponseSequence
    public function response(mixed $body = null, int $status = 200, array $headers = []): Response
    public function assertSent(callable $callback): void
    public function assertNotSent(callable $callback): void
    public function assertSentCount(int $count): void
    public function assertNothingSent(): void
}
```

## Implementation Strategy

### Phase 1: Core Foundation
1. **Create base Factory class** with essential HTTP verbs
2. **Implement PendingRequest** with basic configuration methods
3. **Build Response wrapper** with Laravel-compatible API
4. **Add basic error handling** with custom exceptions

### Phase 2: Advanced Features  
1. **Implement Pool** for concurrent requests
2. **Add comprehensive testing support** with fakes and assertions
3. **Enhance error handling** with provider-specific exceptions
4. **Add middleware support** for request/response processing

### Phase 3: Prism Integration
1. **Provider integration hooks** for seamless integration
2. **Rate limiting support** with automatic retry logic
3. **Enhanced debugging** with request/response logging
4. **Performance optimizations** for high-throughput scenarios

### Phase 4: Migration and Testing
1. **Update existing providers** to use new HTTP client
2. **Comprehensive test suite** covering all functionality
3. **Performance benchmarking** against current implementation
4. **Documentation and examples** for developers

## Usage Examples

### Basic Usage
```php
use Prism\Prism\Facades\Http;

// Simple GET request
$response = Http::get('https://api.example.com/users');
$users = $response->json();

// POST with data
$response = Http::post('https://api.example.com/users', [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// With authentication
$response = Http::withToken($token)
    ->get('https://api.example.com/protected');
```

### Advanced Configuration
```php
// Complex request configuration
$response = Http::withHeaders([
        'User-Agent' => 'Prism/1.0',
        'Accept' => 'application/json'
    ])
    ->withOptions(['verify' => false])
    ->timeout(30)
    ->retry(3, 1000)
    ->withProvider('openai')
    ->post('https://api.openai.com/v1/chat/completions', $data);
```

### Concurrent Requests
```php
$responses = Http::pool(fn (Pool $pool) => [
    $pool->as('users')->get('https://api.example.com/users'),
    $pool->as('posts')->get('https://api.example.com/posts'),
    $pool->as('comments')->get('https://api.example.com/comments'),
]);

$users = $responses['users']->json();
$posts = $responses['posts']->json();  
$comments = $responses['comments']->json();
```

### Testing
```php
use Prism\Prism\Facades\Http;

// In tests
Http::fake([
    'api.example.com/*' => Http::response(['status' => 'success'], 200),
    'api.github.com/*' => Http::response(['error' => 'Not found'], 404),
]);

// Make requests in your code
$response = Http::get('https://api.example.com/users');

// Assert requests were made
Http::assertSent(function ($request) {
    return $request->url() === 'https://api.example.com/users';
});
```

### Provider Integration
```php
// In provider classes
class OpenAI implements Provider
{
    protected function client(array $options, array $retry): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'OpenAI-Organization' => $this->organization,
        ])
        ->withOptions($options)
        ->retry(...$retry)
        ->baseUrl($this->url)
        ->withProvider('openai');
    }
}
```

## Migration Strategy

### Backward Compatibility
- **Gradual migration**: Providers can migrate one at a time
- **Interface compatibility**: Same method signatures and return types
- **Configuration compatibility**: Existing client options work unchanged
- **Testing compatibility**: Existing tests continue to work

### Migration Steps
1. **Install new HTTP client** alongside existing implementation
2. **Update one provider** as a pilot (suggest starting with OpenAI)
3. **Run comprehensive tests** to ensure compatibility
4. **Migrate remaining providers** one by one
5. **Remove Laravel HTTP client dependency** once all providers migrated

## Benefits

### Performance
- **Optimized for Prism**: Tailored specifically for LLM provider interactions
- **Enhanced rate limiting**: Built-in support for provider rate limits
- **Connection pooling**: Efficient connection reuse for multiple requests
- **Async support**: Non-blocking requests for better throughput

### Developer Experience  
- **Familiar API**: Exact same interface as Laravel's HTTP client
- **Enhanced debugging**: Better error messages and logging
- **Type safety**: Full PHPStan compatibility with proper type hints
- **Testing support**: Comprehensive fake and assertion capabilities

### Reliability
- **Provider-aware errors**: Specific exception types for different providers
- **Automatic retries**: Smart retry logic with exponential backoff
- **Circuit breaker**: Prevent cascading failures in provider outages
- **Monitoring hooks**: Built-in metrics and monitoring capabilities

## Testing Strategy

### Unit Tests
- Test all public methods for API compatibility
- Verify request building and configuration
- Test response parsing and error handling
- Validate middleware and authentication logic

### Integration Tests  
- Test with real provider endpoints (rate-limited)
- Verify concurrent request handling
- Test error scenarios and retry logic
- Validate provider-specific integrations

### Performance Tests
- Benchmark against current Laravel HTTP client
- Test concurrent request performance
- Memory usage and connection pooling efficiency
- Rate limiting and retry behavior under load

### Compatibility Tests
- Ensure drop-in replacement capability
- Test existing provider implementations
- Verify testing fake compatibility
- Check configuration option compatibility

## Conclusion

This implementation plan provides a comprehensive roadmap for creating a Guzzle-based HTTP client for Prism that maintains full API compatibility with Laravel's HTTP client while adding valuable Prism-specific enhancements. The phased approach ensures minimal disruption to existing code while providing a clear migration path.

The resulting HTTP client will offer improved performance, better error handling, enhanced testing capabilities, and seamless integration with Prism's provider ecosystem.