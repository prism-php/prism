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
    expect($firstPage->markdown)->toContain('# Text Generation');
    expect($firstPage->markdown)->toContain('## Basic Text Generation');
    expect($firstPage->markdown)->toContain('## System Prompts and Context');

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

it('can combine all pages of the document to one single string', function (): void {
    FixtureResponse::fakeResponseSequence(requestPath: '/ocr', name: 'mistral/ocr-response');

    /** @var Mistral $provider */
    $provider = Prism::provider(Provider::Mistral);

    /** @var OCRResponse $object */
    $object = $provider
        ->ocr(
            model: 'mistral-ocr-latest',
            document: Document::fromUrl(
                url: 'https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf'
            ),
        );

    expect($object->toText())->toContain('# Text Generation');
    expect($object->toText())->toContain('## Basic Text Generation');
    expect($object->toText())->toContain('## System Prompts and Context');
    expect($object->toText())->toContain('# Message Chains and Conversations');
    expect($object->toText())->toContain('## Message Types');
    expect($object->toText())->toContain('# Generation Parameters');
    expect($object->toText())->toContain('# Response Handling');
    expect($object->toText())->toContain('# Finish Reasons');
    expect($object->toText())->toContain('## Error Handling');
});
