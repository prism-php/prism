<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\XAI\XAI;

beforeEach(function (): void {
    $this->provider = new XAI(
        apiKey: 'test-key',
        url: 'https://api.x.ai/v1'
    );
});

function createXAIMockResponse(int $statusCode, array $json = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    return $mockResponse;
}

it('handles errors with detailed error information', function (): void {
    $mockResponse = createXAIMockResponse(400, [
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'Invalid request parameters',
        ],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('grok-2', $exception))
        ->toThrow(PrismException::class, 'XAI Error [400]: invalid_request_error - Invalid request parameters');
});

it('handles errors without error type', function (): void {
    $mockResponse = createXAIMockResponse(500, [
        'error' => ['message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('grok-2', $exception))
        ->toThrow(PrismException::class, 'XAI Error [500]: Internal server error');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createXAIMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('grok-2', $exception))
        ->toThrow(PrismException::class, 'XAI Error [500]: Unknown error');
});
