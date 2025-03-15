<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ObjectSchema;

it('throws an exception for text', function (): void {
    Http::fake()->preventStrayRequests();

    Prism::text()
        ->using(Provider::VoyageAI, 'test-model')
        ->withPrompt('Hello world.')
        ->asText();
})->throws(PrismException::class, 'Prism\Prism\Providers\VoyageAI\VoyageAI::text is not supported by VoyageAI');

it('throws an exception for structured', function (): void {
    Http::fake()->preventStrayRequests();

    Prism::structured()
        ->using(Provider::VoyageAI, 'test-model')
        ->withSchema(new ObjectSchema('', '', []))
        ->withPrompt('Hello world.')
        ->asStructured();
})->throws(PrismException::class, 'Prism\Prism\Providers\VoyageAI\VoyageAI::structured is not supported by VoyageAI');
