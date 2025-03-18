<?php

declare(strict_types=1);

namespace Tests\Providers\Mistral;

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Mistral\Mistral;

beforeEach(function (): void {
    config()->set('prism.providers.mistral.api_key', env('MISTRAL_API_KEY', 'sk-1234'));
});

describe('OCR reading', function (): void {
    it('can read a basic pdf', function (): void {

        /** @var $provider Mistral */
        $provider = Prism::provider(Provider::Mistral);
        $object = $provider
            ->ocr(
                model: 'mistral-ocr-latest',
                documentUrl: 'https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf',
            );

        dd($object);

    });

});
