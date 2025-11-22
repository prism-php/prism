<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

it('supports AnyOfSchema in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/anyof-structured');

    // Create a schema where a field can accept multiple types
    $flexibleContentSchema = new AnyOfSchema(
        schemas: [
            new ObjectSchema(
                name: 'text_content',
                description: 'Text-based content',
                properties: [
                    new StringSchema('type', 'Content type identifier'),
                    new StringSchema('text', 'The text content'),
                    new NumberSchema('word_count', 'Number of words in the text'),
                ],
                requiredFields: ['type', 'text']
            ),
            new ObjectSchema(
                name: 'media_content',
                description: 'Media-based content',
                properties: [
                    new StringSchema('type', 'Content type identifier'),
                    new StringSchema('url', 'Media URL'),
                    new StringSchema('format', 'Media format (jpg, mp4, etc.)'),
                    new BooleanSchema('is_public', 'Whether the media is publicly accessible'),
                ],
                requiredFields: ['type', 'url', 'format']
            ),
        ],
        name: 'content',
        description: 'Content that can be either text or media'
    );

    $rootSchema = new ObjectSchema(
        name: 'post',
        description: 'A social media post with flexible content',
        properties: [
            new StringSchema('title', 'Post title'),
            new ArraySchema(
                name: 'items',
                description: 'List of content items in the post',
                items: $flexibleContentSchema
            ),
        ],
        requiredFields: ['title', 'items']
    );

    $response = Prism::structured()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($rootSchema)
        ->withPrompt('Create a social media post about AI with mixed content types')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys(['title', 'items']);
    expect($response->structured['title'])->toBeString();
    expect($response->structured['items'])->toBeArray();
});

it('supports nullable AnyOfSchema in structured output', function (): void {
    FixtureResponse::fakeResponseSequence('v1/responses', 'openai/nullable-anyof-structured');

    $flexibleValueSchema = new AnyOfSchema(
        schemas: [
            new StringSchema('text', 'A text value'),
            new NumberSchema('number', 'A numeric value'),
            new BooleanSchema('flag', 'A boolean value'),
        ],
        name: 'flexible_value',
        description: 'A value that can be string, number, boolean, or null',
        nullable: true
    );

    $rootSchema = new ObjectSchema(
        name: 'config',
        description: 'Configuration object with flexible values',
        properties: [
            new StringSchema('name', 'Configuration name'),
            $flexibleValueSchema,
        ],
        requiredFields: ['name', 'flexible_value']
    );

    $response = Prism::structured()
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($rootSchema)
        ->withPrompt('Create a config with a flexible value that could be null')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys(['name', 'flexible_value']);
});
