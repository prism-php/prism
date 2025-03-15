<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;

beforeEach(function (): void {
    config()->set('prism.providers.deepseek.api_key', env('DEEPSEEK_API_KEY'));
});

it('Throws exception for embeddings', function (): void {
    $this->expectException(PrismException::class);

    Prism::embeddings()
        ->using(Provider::DeepSeek, 'deepseek-chat')
        ->fromInput('Hello, how are you?')
        ->asEmbeddings();
});
