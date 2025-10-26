<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.openrouter.api_key', env('OPENROUTER_API_KEY'));
});

it('Throws exception for embeddings', function (): void {
    $this->expectException(PrismException::class);

    Prism::embeddings()
        ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
        ->fromInput('Hello, how are you?')
        ->asEmbeddings();
});
