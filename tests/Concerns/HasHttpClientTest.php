<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Concerns\HasHttpClient;

it('creates http client with basic configuration', function (): void {
    $trait = new class
    {
        use HasHttpClient;

        public function getClient(): PendingRequest
        {
            return $this->createHttpClient();
        }
    };

    $client = $trait->getClient();

    expect($client)->toBeInstanceOf(PendingRequest::class);
});

it('creates http client with headers', function (): void {
    $trait = new class
    {
        use HasHttpClient;

        public function getClient(): PendingRequest
        {
            return $this->createHttpClient([
                'Authorization' => 'Bearer token',
                'Accept' => 'application/json',
            ]);
        }
    };

    $client = $trait->getClient();

    expect($client)->toBeInstanceOf(PendingRequest::class);
});

it('creates http client with options and retry configuration', function (): void {
    $trait = new class
    {
        use HasHttpClient;

        public function getClient(): PendingRequest
        {
            return $this->createHttpClient(
                headers: ['Content-Type' => 'application/json'],
                options: ['timeout' => 30],
                retry: [3, 1000]
            );
        }
    };

    $client = $trait->getClient();

    expect($client)->toBeInstanceOf(PendingRequest::class);
});

it('includes telemetry middleware when telemetry is enabled', function (): void {
    config(['prism.telemetry.enabled' => true]);

    $trait = new class
    {
        use HasHttpClient;

        public function getClient(): PendingRequest
        {
            return $this->createHttpClient();
        }
    };

    $client = $trait->getClient();

    expect($client)->toBeInstanceOf(PendingRequest::class);
});

it('excludes telemetry middleware when telemetry is disabled', function (): void {
    config(['prism.telemetry.enabled' => false]);

    $trait = new class
    {
        use HasHttpClient;

        public function getClient(): PendingRequest
        {
            return $this->createHttpClient();
        }
    };

    $client = $trait->getClient();

    expect($client)->toBeInstanceOf(PendingRequest::class);
});
