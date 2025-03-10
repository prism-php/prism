<?php

declare(strict_types=1);

namespace Tests;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

it('they can have nested properties', function (): void {
    $schema = new ObjectSchema(
        name: 'user',
        description: 'a user object',
        properties: [
            new StringSchema('name', 'the users name'),
            new NumberSchema('age', 'the users age'),
            new EnumSchema(
                name: 'status',
                description: 'the users status',
                options: [
                    'active',
                    'inactive',
                    'suspended',
                ]
            ),
            new ArraySchema(
                name: 'hobbies',
                description: 'the users hobbies',
                items: new StringSchema('hobby', 'the users hobby')
            ),
            new ObjectSchema(
                name: 'address',
                description: 'the users address',
                properties: [
                    new StringSchema('street', 'the street part of the address'),
                    new StringSchema('city', 'the city part of the address'),
                    new StringSchema('country', 'the country part of the address'),
                    new NumberSchema('zip', 'the zip code part of the address'),
                ],
                requiredFields: ['street', 'city', 'country', 'zip']
            ),
        ]
    );

    expect($schema->toArray())->toBe([
        'description' => 'a user object',
        'type' => 'object',
        'properties' => [
            'name' => [
                'description' => 'the users name',
                'type' => 'string',
            ],
            'age' => [
                'description' => 'the users age',
                'type' => 'number',
            ],
            'status' => [
                'description' => 'the users status',
                'enum' => [
                    'active',
                    'inactive',
                    'suspended',
                ],
                'type' => 'string',
            ],
            'hobbies' => [
                'description' => 'the users hobbies',
                'type' => 'array',
                'items' => [
                    'description' => 'the users hobby',
                    'type' => 'string',
                ],
            ],
            'address' => [
                'description' => 'the users address',
                'type' => 'object',
                'properties' => [
                    'street' => [
                        'description' => 'the street part of the address',
                        'type' => 'string',
                    ],
                    'city' => [
                        'description' => 'the city part of the address',
                        'type' => 'string',
                    ],
                    'country' => [
                        'description' => 'the country part of the address',
                        'type' => 'string',
                    ],
                    'zip' => [
                        'description' => 'the zip code part of the address',
                        'type' => 'number',
                    ],
                ],
                'required' => ['street', 'city', 'country', 'zip'],
                'additionalProperties' => false,
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ]);
});

it('they can be nullable', function (): void {
    $schema = new ObjectSchema(
        name: 'user',
        description: 'a user object',
        properties: [
            new StringSchema('name', 'the users name', nullable: true),
            new NumberSchema('age', 'the users age', nullable: true),
            new EnumSchema(
                name: 'status',
                description: 'the users status',
                options: [
                    'active',
                    'inactive',
                    'suspended',
                ],
                nullable: true
            ),
            new ArraySchema(
                name: 'hobbies',
                description: 'the users hobbies',
                items: new StringSchema('hobby', 'the users hobby'),
                nullable: true
            ),
            new BooleanSchema(name: 'is_admin', description: 'is an administrative user', nullable: true),
            new ObjectSchema(
                name: 'address',
                description: 'the users address',
                properties: [
                    new StringSchema('street', 'the street part of the address'),
                    new StringSchema('city', 'the city part of the address'),
                    new StringSchema('country', 'the country part of the address'),
                    new NumberSchema('zip', 'the zip code part of the address'),
                ],
                requiredFields: ['street', 'city', 'country', 'zip']
            ),
        ],
        nullable: true
    );

    expect($schema->toArray())->toBe([
        'description' => 'a user object',
        'type' => ['object', 'null'],
        'properties' => [
            'name' => [
                'description' => 'the users name',
                'type' => ['string', 'null'],
            ],
            'age' => [
                'description' => 'the users age',
                'type' => ['number', 'null'],
            ],
            'status' => [
                'description' => 'the users status',
                'enum' => [
                    'active',
                    'inactive',
                    'suspended',
                ],
                'type' => ['string', 'null'],
            ],
            'hobbies' => [
                'description' => 'the users hobbies',
                'type' => ['array', 'null'],
                'items' => [
                    'description' => 'the users hobby',
                    'type' => 'string',
                ],
            ],
            'is_admin' => [
                'description' => 'is an administrative user',
                'type' => ['boolean', 'null'],
            ],
            'address' => [
                'description' => 'the users address',
                'type' => 'object',
                'properties' => [
                    'street' => [
                        'description' => 'the street part of the address',
                        'type' => 'string',
                    ],
                    'city' => [
                        'description' => 'the city part of the address',
                        'type' => 'string',
                    ],
                    'country' => [
                        'description' => 'the country part of the address',
                        'type' => 'string',
                    ],
                    'zip' => [
                        'description' => 'the zip code part of the address',
                        'type' => 'number',
                    ],
                ],
                'required' => ['street', 'city', 'country', 'zip'],
                'additionalProperties' => false,
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ]);
});

it('nullable enums include types', function (): void {
    $enumSchema = new EnumSchema(
        name: 'temp',
        description: 'sick or fever temp',
        options: [98.6, 100, 'unknown', 105],
        nullable: true
    );

    expect($enumSchema->toArray())->toBe([
        'description' => 'sick or fever temp',
        'enum' => [98.6, 100, 'unknown', 105],
        'type' => [
            'number',
            'string',
            'null',
        ],
    ]);
});

it('non-nullable enum with single type returns single type', function (): void {
    $enumSchema = new EnumSchema(
        name: 'user_type',
        description: 'the type of user',
        options: ['admin', 'super_admin', 'standard']
    );

    expect($enumSchema->toArray())->toBe([
        'description' => 'the type of user',
        'enum' => ['admin', 'super_admin', 'standard'],
        'type' => 'string',
    ]);
});
