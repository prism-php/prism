<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Support;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class ZAIJSONEncoder
{
    public static function encodeSchema($schema): array
    {
        if ($schema instanceof ObjectSchema) {
            return self::encodeObjectSchema($schema);
        }

        return self::encodePropertySchema($schema);
    }
    public static function jsonEncode($schema, bool $prettyPrint = true): string
    {
        $encoded = self::encodeSchema($schema);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($encoded, $flags);
    }

    private static function encodeObjectSchema(ObjectSchema $schema): array
    {
        $jsonSchema = [
            'type' => 'object',
            'properties' => [],
        ];

        foreach ($schema->properties as $property) {
            $jsonSchema['properties'][$property->name] = self::encodePropertySchema($property);
        }

        if ($schema->requiredFields !== []) {
            $jsonSchema['required'] = $schema->requiredFields;
        }

        if (! $schema->allowAdditionalProperties) {
            $jsonSchema['additionalProperties'] = false;
        }

        return $jsonSchema;
    }

    private static function encodePropertySchema($property): array
    {
        $schema = [];

        if ($property instanceof StringSchema) {
            $schema['type'] = 'string';
        } elseif ($property instanceof BooleanSchema) {
            $schema['type'] = 'boolean';
        } elseif ($property instanceof NumberSchema) {
            $schema['type'] = 'number';
            if (isset($property->minimum)) {
                $schema['minimum'] = $property->minimum;
            }
            if (isset($property->maximum)) {
                $schema['maximum'] = $property->maximum;
            }
        } elseif ($property instanceof EnumSchema) {
            $schema['type'] = 'string';
            $schema['enum'] = $property->options;
        } elseif ($property instanceof ArraySchema) {
            $schema['type'] = 'array';
            if (isset($property->items)) {
                $schema['items'] = self::encodePropertySchema($property->items);
            }
        }

        if (isset($property->nullable) && $property->nullable) {
            $schema['type'] = [$schema['type'] ?? 'string', 'null'];
        }

        return $schema;
    }
}
