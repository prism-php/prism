<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\Ollama\Ollama;

beforeEach(function (): void {
    $this->provider = new Ollama(
        apiKey: '',
        url: 'http://localhost:11434'
    );
});

function createOllamaMockResponse(int $statusCode, array $json = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    return $mockResponse;
}

it('handles errors with error message', function (): void {
    $mockResponse = createOllamaMockResponse(400, [
        'error' => 'model "unknown-model" not found',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('unknown-model', $exception))
        ->toThrow(PrismException::class, 'Ollama Error [400]: model "unknown-model" not found');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createOllamaMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3', $exception))
        ->toThrow(PrismException::class, 'Ollama Error [500]: Unknown error');
});

it('handles server errors', function (): void {
    $mockResponse = createOllamaMockResponse(503, [
        'error' => 'Service unavailable',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('llama3', $exception))
        ->toThrow(PrismException::class, 'Ollama Error [503]: Service unavailable');
});
