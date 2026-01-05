<?php

declare(strict_types=1);

use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\ToolOutput;

it('constructs with result only', function (): void {
    $output = new ToolOutput(
        result: 'Tool execution completed successfully',
    );

    expect($output->result)->toBe('Tool execution completed successfully')
        ->and($output->artifacts)->toBe([])
        ->and($output->hasArtifacts())->toBeFalse();
});

it('constructs with result and artifacts', function (): void {
    $artifact = new Artifact(
        data: 'aW1hZ2UgZGF0YQ==',
        mimeType: 'image/png',
        id: 'img-123',
    );

    $output = new ToolOutput(
        result: 'Image generated successfully',
        artifacts: [$artifact],
    );

    expect($output->result)->toBe('Image generated successfully')
        ->and($output->artifacts)->toHaveCount(1)
        ->and($output->hasArtifacts())->toBeTrue()
        ->and($output->artifacts[0])->toBe($artifact);
});

it('handles multiple artifacts', function (): void {
    $artifacts = [
        new Artifact(
            data: 'cGRmIGRhdGE=',
            mimeType: 'application/pdf',
            id: 'pdf-1',
        ),
        new Artifact(
            data: 'dHh0IGRhdGE=',
            mimeType: 'text/plain',
            id: 'txt-1',
        ),
        new Artifact(
            data: 'anNvbiBkYXRh',
            mimeType: 'application/json',
            id: 'json-1',
        ),
    ];

    $output = new ToolOutput(
        result: 'Multiple files generated',
        artifacts: $artifacts,
    );

    expect($output->artifacts)->toHaveCount(3)
        ->and($output->hasArtifacts())->toBeTrue()
        ->and($output->artifacts[0]->id)->toBe('pdf-1')
        ->and($output->artifacts[1]->id)->toBe('txt-1')
        ->and($output->artifacts[2]->id)->toBe('json-1');
});

it('handles empty result string', function (): void {
    $output = new ToolOutput(
        result: '',
    );

    expect($output->result)->toBe('')
        ->and($output->hasArtifacts())->toBeFalse();
});

it('handles result with json content', function (): void {
    $jsonResult = json_encode(['status' => 'success', 'count' => 42]);
    $output = new ToolOutput(
        result: $jsonResult,
    );

    expect($output->result)->toBe($jsonResult);
});

it('hasArtifacts returns false for empty array', function (): void {
    $output = new ToolOutput(
        result: 'No artifacts here',
        artifacts: [],
    );

    expect($output->hasArtifacts())->toBeFalse();
});

it('works with artifacts created from raw content', function (): void {
    $imageData = 'raw image bytes here';
    $artifact = Artifact::fromRawContent(
        content: $imageData,
        mimeType: 'image/jpeg',
        metadata: ['width' => 800, 'height' => 600],
        id: 'generated-image',
    );

    $output = new ToolOutput(
        result: json_encode(['image_id' => 'generated-image', 'status' => 'complete']),
        artifacts: [$artifact],
    );

    expect($output->hasArtifacts())->toBeTrue()
        ->and($output->artifacts[0]->rawContent())->toBe($imageData)
        ->and($output->artifacts[0]->mimeType)->toBe('image/jpeg');
});
