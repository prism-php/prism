<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/*
 * The suite-wide network guard set in Tests\TestCase and phpunit.xml.dist.
 * These requests are deliberate: each must be stopped before it leaves the
 * machine.
 */

it('refuses an unfaked request through the HTTP client', function (): void {
    expect(fn () => Http::get('https://api.anthropic.com/v1/models'))->toThrow(StrayRequestException::class);
});

it('sends a request that bypasses the HTTP client to the closed local proxy', function (string $url): void {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 5]);

    $body = curl_exec($curl);
    $error = curl_error($curl);

    expect($body)->toBeFalse()
        ->and($error)->toContain('127.0.0.1');
})->with(['http://example.com/', 'https://api.openai.com/v1/models']);

it('sends a Guzzle request made outside the HTTP client to the closed local proxy', function (): void {
    expect(fn (): ResponseInterface => (new Client(['timeout' => 5]))->get('https://api.anthropic.com/v1/models'))
        ->toThrow(ConnectException::class, '127.0.0.1');
});
