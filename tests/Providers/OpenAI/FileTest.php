<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Prism\Prism\Providers\OpenAI\Handlers\File;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {

    config()->set('prism.providers.openai.api_key', env('OPENAI_API_KEY') ?? '123');
    $this->provider = Prism::provider(Provider::OpenAI);
});

it('can upload a file', function (): void {

    FixtureResponse::fakeResponseSequence(config('prism.providers.openai.url').'/'.config('prism.providers.openai.files_endpoint'), 'openai/file-upload-succesful');
    $pendingReq = Http::baseUrl(config('prism.providers.openai.url'));
    $mock = Mockery::mock(File::class, [$pendingReq])->makePartial();
    $mock->shouldAllowMockingProtectedMethods();
    $mock->shouldReceive('openFile')
        ->andReturn(tmpfile());

    $uploadFile = $mock
        ->withFileName('mydata.jsonl')
        ->withPurpose('batch')
        ->withPath('testfolder/')
        ->withDisk('local')
        ->uploadFile();

    expect($uploadFile->fileName)->toBe('mydata.jsonl');
    expect($uploadFile->providerSpecificData['purpose'])->toBe('batch');
});

it('can retrieve information about file', function (): void {
    FixtureResponse::fakeResponseSequence('https://api.openai.com/v1/files/*', 'openai/file-upload-succesful');

    $uploadFile = $this->provider->file()
        ->withFileOutputId('file-abc123')
        ->retrieveFile();

    expect($uploadFile->id)->toBe('file-abc123');
    expect($uploadFile->fileName)->toBe('mydata.jsonl');
    expect($uploadFile->bytes)->toBe(120000);
    expect($uploadFile->providerSpecificData['purpose'])->toBe('batch');
});

it('can list all files in storage', function (): void {
    FixtureResponse::fakeResponseSequence('https://api.openai.com/v1/files', 'openai/file-list-all-files');

    $listFiles = $this->provider->file()
        ->listFiles();

    expect($listFiles->providerSpecificData['data'][0]->id)->toBe('file-abc123');
    expect($listFiles->providerSpecificData['data'][0]->fileName)->toBe('salesOverview.pdf');
    expect($listFiles->providerSpecificData['data'][0]->bytes)->toBe(175);
    expect($listFiles->providerSpecificData['data'][0]->providerSpecificData['purpose'])->toBe('assistants');
    expect($listFiles->providerSpecificData['data'][1]->id)->toBe('file-abc456');
    expect($listFiles->providerSpecificData['data'][1]->fileName)->toBe('puppy.jsonl');
    expect($listFiles->providerSpecificData['data'][1]->bytes)->toBe(140);
    expect($listFiles->providerSpecificData['data'][1]->providerSpecificData['purpose'])->toBe('fine-tune');
    expect($listFiles->providerSpecificData['object'])->toBe('list');
    expect($listFiles->providerSpecificData['has_more'])->toBe(false);
});

it('can delete a file', function (): void {
    FixtureResponse::fakeResponseSequence('https://api.openai.com/v1/files/*', 'openai/file-delete-file');

    $deleteFile = $this->provider->file()
        ->withFileOutputId('file-abc123')
        ->deleteFile();

    expect($deleteFile->providerSpecificData['id'])->toBe('file-abc123');
    expect($deleteFile->providerSpecificData['deleted'])->toBeTrue();
});

it('throws PrismException when no fileoutputid is provided for deleteFile', function (): void {
    expect(fn (): \Prism\Prism\File\DeleteResponse => $this->provider->file()
        ->deleteFile()
    )->toThrow(PrismException::class);
});

it('throws PrismException when no fileoutputId is provided for retrieveFile', function (): void {
    expect(fn (): \Prism\Prism\File\Response => $this->provider->file()
        ->retrieveFile()
    )->toThrow(PrismException::class);
});

it('can download content of a file', function (): void {
    FixtureResponse::fakeResponseSequence('https://api.openai.com/v1/files/*/content', 'openai/file-download-content');

    $downloadFile = $this->provider->file()
        ->withFileOutputId('file-abc123')
        ->downloadFile();
    $decoded = json_decode((string) $downloadFile, true);
    expect($decoded['content'])->toBe('Prism is pretty cool');
});

it('throws PrismException when fileOutputId is not specified for downloading content of a file', function (): void {
    expect(fn (): string => $this->provider->file()
        ->downloadFile()
    )->toThrow(PrismException::class);
});
