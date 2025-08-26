<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\File\PendingRequest;

beforeEach(function (): void {
    $this->pendingRequest = new PendingRequest;
});

it('can configure storage path', function (): void {
    $this->pendingRequest->using(Provider::OpenAI, 'gpt-4.1')->withDisk('s3')->withPath('pathToFiles');

    expect($this->pendingRequest->toRequest()->disk())->toBe('s3');
    expect($this->pendingRequest->toRequest()->path())->toBe('pathToFiles');
});

it('can configure purpose', function (): void {
    $this->pendingRequest->using(Provider::OpenAI, 'gpt-4.1')->withPurpose('batch');

    expect($this->pendingRequest->toRequest()->purpose())->toBe('batch');
});

it('can configure filename', function (): void {
    $this->pendingRequest->using(Provider::OpenAI, 'gpt-4.1')->withFilename('file.txt');

    expect($this->pendingRequest->toRequest()->filename())->toBe('file.txt');
});

it('can configure fileoutputid', function (): void {
    $this->pendingRequest->using(Provider::OpenAI, 'gpt-4.1')->withFileOutputId('12345');

    expect($this->pendingRequest->toRequest()->fileOutputId())->toBe('12345');
});
