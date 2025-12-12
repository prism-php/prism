<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\DeepSeek\DeepSeek;

beforeEach(function (): void {
    $this->provider = new DeepSeek(
        apiKey: 'test-key',
        url: 'https://api.deepseek.com/v1'
    );
});

function createDeepSeekMockResponse(int $statusCode, array $json = [], array $headers = []): Response
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

it('handles insufficient balance errors (402)', function (): void {
    $mockResponse = createDeepSeekMockResponse(402, [
        'error' => ['message' => 'Insufficient balance'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('deepseek-chat', $exception))
        ->toThrow(PrismException::class, 'DeepSeek Insufficient Balance: Insufficient balance');
});

it('handles unknown errors with default behavior', function (): void {
    $mockResponse = createDeepSeekMockResponse(500, [
        'error' => ['message' => 'Internal server error'],
    ]);
    $exception = new RequestException($mockResponse);

    expect(fn () => $this->provider->handleRequestException('deepseek-chat', $exception))
        ->toThrow(PrismException::class, 'Sending to model (deepseek-chat) failed');
});
