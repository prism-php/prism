<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Support;

use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class ZAIJSONEncoder
{
    /**
     * @param  Schema  $schema
     * @return array<string, mixed>
     */
    public static function encodeSchema($schema): array
    {
        if ($schema instanceof ObjectSchema) {
            return self::encodeObjectSchema($schema);
        }

        return self::encodePropertySchema($schema);
    }

    public static function jsonEncode(Schema $schema, bool $prettyPrint = true): string
    {
        $encoded = self::encodeSchema($schema);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $result = json_encode($encoded, $flags);
        if ($result === false) {
            throw new \RuntimeException('Failed to encode schema to JSON: '.json_last_error_msg());
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function encodeObjectSchema(ObjectSchema $schema): array
    {
        $jsonSchema = [
            'type' => 'object',
            'properties' => [],
        ];

        if (isset($schema->description)) {
            $jsonSchema['description'] = $schema->description;
        }

        foreach ($schema->properties as $property) {
            // Use name() method which is defined in Schema interface
            $propertyName = method_exists($property, 'name') ? $property->name() : $property->name ?? 'unknown';
            $jsonSchema['properties'][$propertyName] = self::encodePropertySchema($property);
        }

        if ($schema->requiredFields !== []) {
            $jsonSchema['required'] = $schema->requiredFields;
        }

        if (! $schema->allowAdditionalProperties) {
            $jsonSchema['additionalProperties'] = false;
        }

        // Handle nullable for objects
        if (isset($schema->nullable) && $schema->nullable) {
            $jsonSchema['type'] = [$jsonSchema['type'], 'null'];
        }

        return $jsonSchema;
    }

    /**
     * @param  Schema  $property
     * @return array<string, mixed>
     */
    protected static function encodePropertySchema($property): array
    {
        $schema = [];

        if ($property instanceof StringSchema) {
            $schema['type'] = 'string';
            if (isset($property->description)) {
                $schema['description'] = $property->description;
            }
        } elseif ($property instanceof BooleanSchema) {
            $schema['type'] = 'boolean';
            if (isset($property->description)) {
                $schema['description'] = $property->description;
            }
        } elseif ($property instanceof NumberSchema) {
            $schema['type'] = 'number';
            if (isset($property->description)) {
                $schema['description'] = $property->description;
            }
            if (isset($property->minimum)) {
                $schema['minimum'] = $property->minimum;
            }
            if (isset($property->maximum)) {
                $schema['maximum'] = $property->maximum;
            }
        } elseif ($property instanceof EnumSchema) {
            $schema['type'] = 'string';
            $schema['enum'] = $property->options;
            if (isset($property->description)) {
                $schema['description'] = $property->description;
            }
        } elseif ($property instanceof ObjectSchema) {
            $schema = self::encodeObjectSchema($property);
        } elseif ($property instanceof ArraySchema) {
            $schema['type'] = 'array';
            if (isset($property->description)) {
                $schema['description'] = $property->description;
            }

            if (isset($property->items)) {
                $schema['items'] = self::encodePropertySchema($property->items);
            }

            if (isset($property->minItems)) {
                $schema['minItems'] = $property->minItems;
            }
            if (isset($property->maxItems)) {
                $schema['maxItems'] = $property->maxItems;
            }
        }

        if (property_exists($property, 'nullable') && $property->nullable !== null && $property->nullable) {
            $schema['type'] = [$schema['type'] ?? 'string', 'null'];
        }

        return $schema;
    }
}
