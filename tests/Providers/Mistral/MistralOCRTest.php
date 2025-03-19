<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Mistral\Mistral;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRPageResponse;
use Prism\Prism\Providers\Mistral\ValueObjects\OCRResponse;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'sk-1234'));
});

it('can read a basic pdf', function (): void {
    FixtureResponse::fakeResponseSequence(requestPath: '/ocr', name: 'mistral/ocr-response');

    /** @var Mistral $provider */
    $provider = Prism::provider(Provider::Mistral);

    $object = $provider
        ->ocr(
            model: 'mistral-ocr-latest',
            document: Document::fromUrl(
                url: 'https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf'
            ),
        );

    expect($object->model)->toBe('mistral-ocr-latest');
    /** @var OCRPageResponse $firstPage */
    /** @var OCRResponse $object */
    $firstPage = $object->pages[0];
    expect($firstPage->index)->toBe(0);
    expect($firstPage->markdown)->toBe('# Text Generation 

Prism provides a powerful interface for generating text using Large Language Models (LLMs). This guide covers everything from basic usage to advanced features like multimodal interactions and response handling.

## Basic Text Generation

At its simplest, you can generate text with just a few lines of code:

```
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
$response = Prism::text()
    ->using(Provider::Anthropic, \'claude-3-5-sonnet-20241022\')
    ->withPrompt(\'Tell me a short story about a brave knight.\')
    ->asText();
echo $response->text;
```


## System Prompts and Context

System prompts help set the behavior and context for the AI. They\'re particularly useful for maintaining consistent responses or giving the LLM a persona:

```
php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
$response = Prism::text()
->using(Provider::Anthropic, \'claude-3-5-sonnet-20241022\')
```');
    expect($firstPage->images)->toBe([]);
    expect($firstPage->dimensions)->toBe([
        'dpi' => 200,
        'height' => 2200,
        'width' => 1700,
    ]);
    expect($object->usageInfo)->toBe([
        'pages_processed' => 6,
        'doc_size_bytes' => 306115,
    ]);

});
