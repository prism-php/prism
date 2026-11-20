# Schemas

Schemas define the structure and types of tool parameters and structured output.

## Quick Start

Define an object with named properties and required fields:

> [!IMPORTANT]
> **Structured Output Requirement**: When using schemas for structured output with providers like OpenAI (especially in strict mode), the root schema should be an `ObjectSchema`. Other schema types can only be used as properties within an ObjectSchema, not as the top-level schema. Different providers may have varying requirements.

```php
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$userSchema = new ObjectSchema(
    name: 'user',
    description: 'A user profile with their hobbies',
    properties: [
        new StringSchema('name', 'The user\'s full name'),
        new ArraySchema(
            name: 'hobbies',
            description: 'The user\'s list of hobbies',
            items: new ObjectSchema(
                name: 'hobby',
                description: 'A detailed hobby entry',
                properties: [
                    new StringSchema('name', 'The name of the hobby'),
                    new StringSchema('description', 'A brief description of the hobby'),
                ],
                requiredFields: ['name', 'description']
            )
        ),
    ],
    requiredFields: ['name', 'hobbies']
);
```

## Available Schema Types

### StringSchema

For text values, such as names and descriptions.

```php
use Prism\Prism\Schema\StringSchema;

$nameSchema = new StringSchema(
    name: 'full_name',
    description: 'The user\'s full name including first and last name'
);
```

### NumberSchema

For numeric values, such as quantities and measurements.

```php
use Prism\Prism\Schema\NumberSchema;

$ageSchema = new NumberSchema(
    name: 'age',
    description: 'The user\'s age in years'
);
```

### BooleanSchema

For boolean values.

```php
use Prism\Prism\Schema\BooleanSchema;

$activeSchema = new BooleanSchema(
    name: 'is_active',
    description: 'Whether the user account is active'
);
```

### ArraySchema

For lists of items, where each item follows a specific schema.

```php
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\StringSchema;

$tagsSchema = new ArraySchema(
    name: 'tags',
    description: 'List of tags associated with the post',
    items: new StringSchema('tag', 'A single tag')
);
```

### EnumSchema

When you need to restrict values to a specific set of options.

```php
use Prism\Prism\Schema\EnumSchema;

$statusSchema = new EnumSchema(
    name: 'status',
    description: 'The current status of the post',
    options: ['draft', 'published', 'archived']
);
```

### ObjectSchema

For objects with named properties, including nested objects.

> [!NOTE]
> ObjectSchema is typically required as the root schema for structured output operations with providers like OpenAI. It's the recommended schema type to use directly with `withSchema()` in structured output requests, though different providers may have varying requirements.

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$profileSchema = new ObjectSchema(
    name: 'profile',
    description: 'A user\'s public profile information',
    properties: [
        new StringSchema('username', 'The unique username'),
        new StringSchema('bio', 'A short biography'),
        new NumberSchema('joined_year', 'Year the user joined'),
    ],
    requiredFields: ['username']
);
```

### AnyOfSchema

For flexible data that can match one of several schemas. This is particularly useful when you need to handle different data types or structures in the same field.

Each nested schema must be valid JSON Schema for the selected provider and model. Check its supported schema keywords before using `AnyOfSchema`.

```php
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;

// Simple example: A value that can be either a string or number
$flexibleValueSchema = new AnyOfSchema(
    schemas: [
        new StringSchema('text', 'A text value'),
        new NumberSchema('number', 'A numeric value'),
    ],
    name: 'flexible_value',
    description: 'A value that can be either text or numeric'
);

// Complex example: Different content types
$contentSchema = new AnyOfSchema(
    schemas: [
        new ObjectSchema(
            name: 'article',
            description: 'A blog article',
            properties: [
                new StringSchema('title', 'Article title'),
                new StringSchema('content', 'Article content'),
                new StringSchema('author', 'Article author'),
            ],
            requiredFields: ['title', 'content']
        ),
        new ObjectSchema(
            name: 'image',
            description: 'An image post',
            properties: [
                new StringSchema('url', 'Image URL'),
                new StringSchema('caption', 'Image caption'),
                new NumberSchema('width', 'Image width in pixels'),
                new NumberSchema('height', 'Image height in pixels'),
            ],
            requiredFields: ['url']
        ),
    ],
    name: 'content',
    description: 'Content that can be either an article or an image'
);
```

`AnyOfSchema` combines alternative schema branches. Its `toArray()` includes those alternatives; provider-specific mapping can adjust them. Support depends on the provider's schema dialect, selected structured mode and model. Check the [provider guides](/providers/openai#structured-output-mode) and validate the result in your application.

## Raw JSON Schema and constraints

Use `RawSchema` when you already have a schema document or need keywords outside the typed constructors:

```php
use Prism\Prism\Schema\RawSchema;

$schema = new RawSchema('reference', [
    'type' => 'object',
    'properties' => ['code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$']],
    'required' => ['code'],
    'additionalProperties' => false,
]);
```

`RawSchema` sends the supplied array unchanged. It does not validate that document or make unsupported keywords available on a provider. Validate returned data for your application's requirements.

The typed constructors expose these options after their required name/description and child-schema arguments:

| Schema | Optional constraints |
|---|---|
| `StringSchema` | `nullable`, `pattern`, `format` |
| `NumberSchema` | `nullable`, `multipleOf`, `maximum`, `exclusiveMaximum`, `minimum`, `exclusiveMinimum` |
| `ArraySchema` | `nullable`, `minItems`, `maxItems` |
| `ObjectSchema` | `requiredFields`, `allowAdditionalProperties` (default false), `nullable` |

Use named arguments for constraints. A provider may support only a subset of JSON Schema; supplying a constraint does not guarantee that every provider enforces it.

## Nullable Fields

Sometimes, not every field is required. You can make any schema nullable by setting the `nullable` parameter to `true`:

```php
use Prism\Prism\Schema\StringSchema;

$bioSchema = new StringSchema(
    name: 'bio',
    description: 'Optional user biography',
    nullable: true
);
```

> [!NOTE]
> When using OpenAI in strict mode, all fields must be marked as required, so optional fields must be marked as nullable.

## Required vs Nullable Fields

Understanding the difference between required fields and nullable fields is crucial when working with schemas in Prism:

### Required Fields

Required fields are specified at the object level using the `requiredFields` parameter. They indicate which properties must be present in the data structure:

```php
$userSchema = new ObjectSchema(
    name: 'user',
    description: 'User profile',
    properties: [
        new StringSchema('email', 'Primary email address'),
        new StringSchema('name', 'User\'s full name'),
        new StringSchema('bio', 'User biography', nullable: true), // bio can be null
    ],
    requiredFields: ['email', 'name', 'bio'] // all fields must be present
);
```

### Nullable Fields

Nullable fields, on the other hand, are specified at the individual field level using the `nullable` parameter. They indicate that a field can contain a `null` value:

```php
$userSchema = new ObjectSchema(
    name: 'user',
    description: 'User profile',
    properties: [
        new StringSchema('email', 'Primary email address'),
        new StringSchema('name', 'User\'s full name'),
        new StringSchema('bio', 'User biography', nullable: true), // bio can be null
    ],
    requiredFields: ['email', 'name', 'bio'] // bio must be present, but can be null
);
```

### Key Differences

1. **Required vs Present**: 
   - A required field must be present in the data structure
   - A non-nullable field must contain a non-null value when present
   - A field can be required but nullable (must be present, can be null)
   - A field can be non-required and non-nullable (when present, cannot be null)

2. **Common Patterns**:
```php
// Required and Non-nullable (most strict)
new StringSchema('email', 'Primary email', nullable: false);
// requiredFields: ['email']

// Required but Nullable (must be present, can be null)
new StringSchema('bio', 'User bio', nullable: true);
// requiredFields: ['bio']

// Optional and Non-nullable (can be omitted, but if present cannot be null)
new StringSchema('phone', 'Phone number', nullable: false);
// requiredFields: []

// Optional and Nullable (most permissive)
new StringSchema('website', 'Personal website', nullable: true);
// requiredFields: []
```

### Provider Considerations

When working with providers that support strict mode (like OpenAI), you'll want to be especially careful with these settings:

```php
// For OpenAI strict mode: 
// - All fields should be required
// - Use nullable: true for optional fields
$userSchema = new ObjectSchema(
    name: 'user',
    description: 'User profile',
    properties: [
        new StringSchema('email', 'Required email address'),
        new StringSchema('bio', 'Optional biography', nullable: true),
    ],
    requiredFields: ['email', 'bio'] // Note: bio is required but nullable
);
```

> [!TIP]
> When in doubt, be explicit about both requirements. Specify both the `nullable` status of each field AND which fields are required in your object schemas. This makes your intentions clear to both other developers and AI providers.

## Best Practices

1. **Clear Descriptions**: Write clear, concise descriptions for each field.
   ```php
   // ❌ Not helpful
   new StringSchema('name', 'the name');

   // ✅ Much better
   new StringSchema('name', 'The user\'s display name (2-50 characters)');
   ```

2. **Thoughtful Required Fields**: Only mark fields as required if they're truly necessary:
   ```php
   new ObjectSchema(
       name: 'user',
       description: 'User profile',
       properties: [
           new StringSchema('email', 'Primary email address'),
           new StringSchema('phone', 'Optional phone number', nullable: true),
       ],
       requiredFields: ['email']
   );
   ```

3. **Nested Organization**: Keep your schemas organized when dealing with complex structures:
   ```php
   // Define child schemas first
   $addressSchema = new ObjectSchema(/*...*/);
   $contactSchema = new ObjectSchema(/*...*/);

   // Then use them in your parent schema
   $userSchema = new ObjectSchema(
       name: 'user',
       description: 'Complete user profile',
       properties: [$addressSchema, $contactSchema]
   );
   ```

> [!NOTE]
> Prism defines schemas for provider requests but does not validate returned data against them. Validate responses in your application.
