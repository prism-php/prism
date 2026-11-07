<?php

declare(strict_types=1);

namespace Tests\Providers\Ollama;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('refuses an image built from a url, without fetching it', function (): void {
    // Ollama takes image bytes only. A URL image used to be fetched implicitly
    // and inlined, through an unguarded server-side request. It is now refused
    // with the explicit alternative named, and nothing is sent.
    Http::fake(['*' => Http::response('never used')]);

    expect(fn () => Prism::text()
        ->using(Provider::Ollama, 'llava')
        ->withMessages([
            new UserMessage('What is in this image?', [Image::fromUrl('http://169.254.169.254/latest/meta-data/')]),
        ])
        ->asText())
        ->toThrow(PrismException::class, 'fetchUrlContent()');

    Http::assertSentCount(0);
});
