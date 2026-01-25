<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Anthropic\Anthropic;

beforeEach(function (): void {
    $this->provider = new Anthropic(
        apiKey: 'test-key',
        apiVersion: '2023-06-01',
        url: 'https://api.anthropic.com/v1'
    );
});

function createAnthropicMockResponse(int $statusCode, array $json = [], array $headers = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    if (isset($headers['retry-after'])) {
        $mockResponse->shouldReceive('hasHeader')->with('retry-after')->andReturn(true);
        $mockResponse->shouldReceive('getHeader')->with('retry-after')->andReturn([$headers['retry-after']]);
    } else {
        $mockResponse->shouldReceive('hasHeader')->with('retry-after')->andReturn(false);
    }

    // Rate limit headers for Anthropic
    $mockResponse->shouldReceive('getHeaders')->andReturn([]);

    return $mockResponse;
}

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createAnthropicMockResponse(429, [
        'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('claude-3-opus', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles provider overloaded errors (529)', function (): void {
    $mockResponse = createAnthropicMockResponse(529, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('claude-3-opus', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles request too large errors (413)', function (): void {
    $mockResponse = createAnthropicMockResponse(413, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('claude-3-opus', $exception))
        ->toThrow(PrismRequestTooLargeException::class);
});

it('handles errors with detailed error information', function (): void {
    $mockResponse = createAnthropicMockResponse(400, [
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'messages: text content blocks must be non-empty',
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('claude-3-opus', $exception))
        ->toThrow(PrismException::class, 'Anthropic Error [400]: invalid_request_error - messages: text content blocks must be non-empty');
});

it('handles errors without error type', function (): void {
    $mockResponse = createAnthropicMockResponse(500, [
        'error' => ['message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('claude-3-opus', $exception))
        ->toThrow(PrismException::class, 'Anthropic Error [500]: Internal server error');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createAnthropicMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('claude-3-opus', $exception))
        ->toThrow(PrismException::class, 'Anthropic Error [500]: Unknown error');
});
