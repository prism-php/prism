<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\Groq\Groq;

beforeEach(function (): void {
    $this->provider = new Groq(
        apiKey: 'test-key',
        url: 'https://api.groq.com/openai/v1'
    );
});

function createGroqMockResponse(int $statusCode, array $json = [], array $headers = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));
    $mockResponse->shouldReceive('header')->with('retry-after')->andReturn($headers['retry-after'] ?? '0');
    $mockResponse->shouldReceive('getHeaders')->andReturn([]);

    return $mockResponse;
}

it('handles rate limit errors (429)', function (): void {
    $mockResponse = createGroqMockResponse(429, [
        'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
    ], ['retry-after' => '60']);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3-8b-8192', $exception))
        ->toThrow(PrismRateLimitedException::class);
});

it('handles provider overloaded errors (529)', function (): void {
    $mockResponse = createGroqMockResponse(529, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3-8b-8192', $exception))
        ->toThrow(PrismProviderOverloadedException::class);
});

it('handles request too large errors (413)', function (): void {
    $mockResponse = createGroqMockResponse(413, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3-8b-8192', $exception))
        ->toThrow(PrismRequestTooLargeException::class);
});

it('handles errors with detailed error information', function (): void {
    $mockResponse = createGroqMockResponse(400, [
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'Invalid request parameters',
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3-8b-8192', $exception))
        ->toThrow(PrismException::class, 'Groq Error [400]: invalid_request_error - Invalid request parameters');
});

it('handles errors without error type', function (): void {
    $mockResponse = createGroqMockResponse(500, [
        'error' => ['message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3-8b-8192', $exception))
        ->toThrow(PrismException::class, 'Groq Error [500]: Internal server error');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createGroqMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3-8b-8192', $exception))
        ->toThrow(PrismException::class, 'Groq Error [500]: Unknown error');
});
