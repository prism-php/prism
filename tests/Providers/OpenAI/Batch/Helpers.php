<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI\Batch;

use Prism\Prism\Batch\BatchRequest;
use Prism\Prism\Batch\BatchRequestItem;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Request as TextRequest;

function createOpenAITextRequest(string $prompt): TextRequest
{
    return Prism::text()
        ->using('openai', 'gpt-4o')
        ->withPrompt($prompt)
        ->toRequest();
}

function openaiFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../../Fixtures/openai/'.$name);
}

function createOpenAIBatchRequest(int $count = 2, ?string $inputFileId = null): BatchRequest
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new BatchRequestItem(
            customId: "request-{$i}",
            request: createOpenAITextRequest("Hello {$i}"),
        );
    }

    return new BatchRequest(items: $items, inputFileId: $inputFileId);
}
