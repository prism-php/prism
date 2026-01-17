<?php

return [
    'prism_server' => [
        // The middleware that will be applied to the Prism Server routes.
        'middleware' => [],
        'enabled' => env('PRISM_SERVER_ENABLED', false),
    ],
    'request_timeout' => env('PRISM_REQUEST_TIMEOUT', 30), // The timeout for requests in seconds.
    'providers' => [
        'openai' => [
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY', ''),
            'organization' => env('OPENAI_ORGANIZATION', null),
            'project' => env('OPENAI_PROJECT', null),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
            // Include beta strings as a comma separated list.
            'anthropic_beta' => env('ANTHROPIC_BETA', null),
        ],
        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY', ''),
            'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
        ],
        'groq' => [
            'api_key' => env('GROQ_API_KEY', ''),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
        ],
        'xai' => [
            'api_key' => env('XAI_API_KEY', ''),
            'url' => env('XAI_URL', 'https://api.x.ai/v1'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', ''),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1'),
        ],
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY', ''),
            'url' => env('ELEVENLABS_URL', 'https://api.elevenlabs.io/v1/'),
        ],
        'voyageai' => [
            'api_key' => env('VOYAGEAI_API_KEY', ''),
            'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY', ''),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
            'site' => [
                'http_referer' => env('OPENROUTER_SITE_HTTP_REFERER', null),
                'x_title' => env('OPENROUTER_SITE_X_TITLE', null),
            ],
        ],
    ],
    'telemetry' => [
        'enabled' => env('PRISM_TELEMETRY_ENABLED', false),
        'driver' => env('PRISM_TELEMETRY_DRIVER', 'null'),

        // Each named driver config specifies a 'driver' key for the actual driver type.
        // This allows multiple configs using the same underlying driver (e.g., multiple OTLP endpoints).
        'drivers' => [
            'null' => [
                'driver' => 'null',
            ],

            'log' => [
                'driver' => 'log',
                'channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'prism-telemetry'),
            ],

            // Phoenix Arize - OTLP with OpenInference semantic conventions
            'phoenix' => [
                'driver' => 'otlp',
                'endpoint' => env('PHOENIX_ENDPOINT', 'https://app.phoenix.arize.com/v1/traces'),
                'api_key' => env('PHOENIX_API_KEY'),
                'service_name' => env('PHOENIX_SERVICE_NAME', 'prism'),
                'mapper' => \Prism\Prism\Telemetry\Semantics\OpenInferenceMapper::class,
                'timeout' => 30.0,
                // Tags applied to all spans (useful for filtering)
                'tags' => [
                    'environment' => env('APP_ENV', 'production'),
                    'app' => env('APP_NAME', 'laravel'),
                ],
            ],

            // Example: Langfuse OTLP backend
            // 'langfuse' => [
            //     'driver' => 'otlp',
            //     'endpoint' => env('LANGFUSE_ENDPOINT', 'https://cloud.langfuse.com/api/public/otel/v1/traces'),
            //     'api_key' => env('LANGFUSE_API_KEY'),
            //     'service_name' => env('LANGFUSE_SERVICE_NAME', 'prism'),
            //     'mapper' => \Prism\Prism\Telemetry\Semantics\PassthroughMapper::class,
            // ],

            // Example: Custom driver via factory class
            // 'my-custom' => [
            //     'driver' => 'custom',
            //     'via' => App\Telemetry\MyCustomDriverFactory::class,
            //     // Pass any additional config your factory needs...
            // ],
        ],
    ],
];
