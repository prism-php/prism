<?php

declare(strict_types=1);

use Prism\Prism\Providers\Gemini\Maps\SchemaMap;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

it('maps array schema correctly', function (): void {
    $map = (new SchemaMap(new ArraySchema(
        name: 'testArray',
        description: 'test array description',
        items: new StringSchema(
            name: 'testName',
            description: 'test string description',
            nullable: true,
        ),
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'type' => 'array',
        'items' => [
            'type' => 'string',
            'nullable' => true,
        ],
        'nullable' => true,
    ]);
});

it('maps boolean schema correctly', function (): void {
    $map = (new SchemaMap(new BooleanSchema(
        name: 'testBoolean',
        description: 'test description',
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'type' => 'boolean',
        'nullable' => true,
    ]);
});

it('maps enum schema correctly', function (): void {
    $map = (new SchemaMap(new EnumSchema(
        name: 'testEnum',
        description: 'test description',
        options: ['option1', 'option2'],
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'enum' => ['option1', 'option2'],
        'type' => 'string',
        'nullable' => true,
    ]);
});

it('maps number schema correctly', function (): void {
    $map = (new SchemaMap(new NumberSchema(
        name: 'testNumber',
        description: 'test description',
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'type' => 'number',
        'nullable' => true,
    ]);
});

it('maps string schema correctly', function (): void {
    $map = (new SchemaMap(new StringSchema(
        name: 'testName',
        description: 'test description',
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'type' => 'string',
        'nullable' => true,
    ]);
});

it('maps object schema correctly', function (): void {
    $map = (new SchemaMap(new ObjectSchema(
        name: 'testObject',
        description: 'test object description',
        properties: [
            new StringSchema(
                name: 'testName',
                description: 'test string description',
            ),
        ],
        requiredFields: ['testName'],
        allowAdditionalProperties: true,
        nullable: true,
    )))->toArray();

    expect($map)->toBe([
        'type' => 'object',
        'properties' => [
            'testName' => [
                'type' => 'string',
            ],
        ],
        'required' => ['testName'],
        'nullable' => true,
    ]);
});
