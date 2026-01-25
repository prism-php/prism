<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\VoyageAI\VoyageAI;

beforeEach(function (): void {
    $this->provider = new VoyageAI(
        apiKey: 'test-key',
        baseUrl: 'https://api.voyageai.com/v1'
    );
});

function createVoyageAIMockResponse(int $statusCode, array $json = []): Response
{
    $mockResponse = Mockery::mock(Response::class);
    $mockResponse->shouldReceive('getStatusCode')->andReturn($statusCode);
    $mockResponse->shouldReceive('status')->andReturn($statusCode);
    $mockResponse->shouldReceive('json')->andReturn($json);
    $mockResponse->shouldReceive('toPsrResponse')->andReturn(new PsrResponse($statusCode));

    return $mockResponse;
}

it('handles errors with detail message', function (): void {
    $mockResponse = createVoyageAIMockResponse(400, [
        'detail' => 'Invalid input: text cannot be empty',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('voyage-3', $exception))
        ->toThrow(PrismException::class, 'VoyageAI Error [400]: Invalid input: text cannot be empty');
});

it('handles errors without any error details', function (): void {
    $mockResponse = createVoyageAIMockResponse(500, []);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('voyage-3', $exception))
        ->toThrow(PrismException::class, 'VoyageAI Error [500]: Unknown error');
});

it('handles authentication errors', function (): void {
    $mockResponse = createVoyageAIMockResponse(401, [
        'detail' => 'Invalid API key',
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('voyage-3', $exception))
        ->toThrow(PrismException::class, 'VoyageAI Error [401]: Invalid API key');
});
