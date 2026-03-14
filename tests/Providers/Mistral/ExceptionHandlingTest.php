<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Mistral\Mistral;

beforeEach(function (): void {
    $this->provider = new Mistral(
        apiKey: 'test-key',
        url: 'https://api.mistral.ai/v1'
    );
});

function createMistralMockResponse(int $statusCode, array $json = [], array $headers = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));
    $mockResponse->shouldReceive('getHeaders')->andReturn($headers);
    $mockResponse->shouldReceive('header')->with('ratelimitbysize-limit')->andReturn($headers['ratelimitbysize-limit'] ?? '0');
    $mockResponse->shouldReceive('header')->with('ratelimitbysize-remaining')->andReturn($headers['ratelimitbysize-remaining'] ?? '0');
    $mockResponse->shouldReceive('header')->with('ratelimitbysize-reset')->andReturn($headers['ratelimitbysize-reset'] ?? '0');

    return $mockResponse;
}

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createMistralMockResponse(429, [
        'object' => 'error',
        'message' => 'Rate limit exceeded',
        'type' => 'rate_limit_error',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('mistral-large', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles provider overloaded errors (529)', function (): void {
    $mockResponse = createMistralMockResponse(529, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('mistral-large', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles request too large errors (413)', function (): void {
    $mockResponse = createMistralMockResponse(413, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('mistral-large', $exception))
        ->toThrow(PrismRequestTooLargeException::class);
});

it('handles errors with type and message', function (): void {
    $mockResponse = createMistralMockResponse(400, [
        'object' => 'error',
        'type' => 'invalid_request_error',
        'message' => 'Invalid model specified',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('mistral-large', $exception))
        ->toThrow(PrismException::class, 'Mistral Error [400]: invalid_request_error - Invalid model specified');
});

it('handles errors with object fallback for type', function (): void {
    $mockResponse = createMistralMockResponse(400, [
        'object' => 'error',
        'message' => 'Invalid model specified',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('mistral-large', $exception))
        ->toThrow(PrismException::class, 'Mistral Error [400]: error - Invalid model specified');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createMistralMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('mistral-large', $exception))
        ->toThrow(PrismException::class, 'Mistral Error [500]: Unknown error');
});
