<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenRouter\OpenRouter;

beforeEach(function (): void {
    $this->provider = new OpenRouter(
        apiKey: 'test-key',
        url: 'https://openrouter.ai/api/v1'
    );
});

function createMockResponse(int $statusCode, array $json = [], array $headers = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    if (isset($headers['retry-after'])) {
        $mockResponse->shouldReceive('hasHeader')->with('retry-after')->andReturn(true);
        $mockResponse->shouldReceive('header')->with('retry-after')->andReturn($headers['retry-after']);
    } else {
        $mockResponse->shouldReceive('hasHeader')->with('retry-after')->andReturn(false);
    }

    return $mockResponse;
}

it('handles bad request errors (400)', function (): void {
    $mockResponse = createMockResponse(400, [
        'error' => ['code' => 400, 'message' => 'Invalid request parameters'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Bad Request: Invalid request parameters');
});

it('handles authentication errors (401)', function (): void {
    $mockResponse = createMockResponse(401, [
        'error' => ['code' => 401, 'message' => 'Invalid API key'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Authentication Error: Invalid API key');
});

it('handles insufficient credits errors (402)', function (): void {
    $mockResponse = createMockResponse(402, [
        'error' => ['code' => 402, 'message' => 'Insufficient credits'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Insufficient Credits: Insufficient credits');
});

it('handles moderation errors (403)', function (): void {
    $mockResponse = createMockResponse(403, [
        'error' => ['code' => 403, 'message' => 'Content flagged by moderation'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Moderation Error: Content flagged by moderation');
});

it('handles timeout errors (408)', function (): void {
    $mockResponse = createMockResponse(408, [
        'error' => ['code' => 408, 'message' => 'Request timeout'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Request Timeout: Request timeout');
});

it('handles request too large errors (413)', function (): void {
    $mockResponse = createMockResponse(413, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismRequestTooLargeException::class);
});

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createMockResponse(429, [
        'error' => ['code' => 429, 'message' => 'Rate limit exceeded'],
    ], ['retry-after' => '60']);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles rate limit errors without retry-after header', function (): void {
    $mockResponse = createMockResponse(429, [
        'error' => ['code' => 429, 'message' => 'Rate limit exceeded'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles model error (502)', function (): void {
    $mockResponse = createMockResponse(502, [
        'error' => ['code' => 502, 'message' => 'Model is down'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Model Error: Model is down');
});

it('handles provider overloaded errors (503)', function (): void {
    $mockResponse = createMockResponse(503, [
        'error' => ['code' => 503, 'message' => 'No available providers'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles unknown errors with default behavior', function (): void {
    $mockResponse = createMockResponse(500, [
        'error' => ['code' => 500, 'message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'Sending to model (test-model) failed');
});

it('extracts error message from metadata.raw when available', function (): void {
    $mockResponse = createMockResponse(400, [
        'error' => [
            'code' => 400,
            'message' => 'Provider returned error',
            'metadata' => [
                'raw' => '{"error":{"message":"Invalid schema for response_format: Missing required field","type":"invalid_request_error"}}',
                'provider_name' => 'Azure',
            ],
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Bad Request: Invalid schema for response_format: Missing required field');
});

it('falls back to error.message when metadata.raw is missing', function (): void {
    $mockResponse = createMockResponse(400, [
        'error' => [
            'code' => 400,
            'message' => 'Provider returned error',
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Bad Request: Provider returned error');
});

it('falls back to error.message when metadata.raw has no error.message', function (): void {
    $mockResponse = createMockResponse(400, [
        'error' => [
            'code' => 400,
            'message' => 'Provider returned error',
            'metadata' => [
                'raw' => '{"error":{"type":"invalid_request_error","code":"some_code"}}',
            ],
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Bad Request: Provider returned error');
});

it('falls back to error.message when metadata.raw contains invalid JSON', function (): void {
    $mockResponse = createMockResponse(400, [
        'error' => [
            'code' => 400,
            'message' => 'Provider returned error',
            'metadata' => [
                'raw' => 'not valid json',
            ],
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('test-model', $exception))
        ->toThrow(PrismException::class, 'OpenRouter Bad Request: Provider returned error');
});
