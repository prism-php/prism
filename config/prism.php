<?php

return [
    'prism_server' => [
        // The middleware that will be applied to the Prism Server routes.
        'middleware' => [],
        'enabled' => env('PRISM_SERVER_ENABLED', false),
    ],
    'request_timeout' => env('PRISM_REQUEST_TIMEOUT', 30), // The timeout for requests in seconds.
    'telemetry' => [
        // Master switch. When false, telemetry is a complete no-op: no contexts
        // are minted and no events are dispatched.
        'enabled' => env('PRISM_TELEMETRY_ENABLED', false),

        // Include prompts, completions, and tool arguments in telemetry payloads.
        // Off by default: this data can contain PII. Only enable it where the
        // telemetry sink is trusted.
        'capture_content' => env('PRISM_TELEMETRY_CAPTURE_CONTENT', false),

        // Include attachment BYTES (images, documents, audio, video) in captured
        // content. Off by default: with it off, a media part keeps its kind, mime
        // type and file id, and its bytes are replaced by their size.
        'capture_media' => env('PRISM_TELEMETRY_CAPTURE_MEDIA', false),

        // Bound opt-in stream reconstruction so telemetry cannot turn an
        // otherwise streaming response into unbounded process memory.
        'content_max_length' => (int) env('PRISM_TELEMETRY_CONTENT_MAX_LENGTH', 65_536),
        'content_max_items' => (int) env('PRISM_TELEMETRY_CONTENT_MAX_ITEMS', 256),
    ],
    // A provider's `url` here may point anywhere, including a model server on
    // localhost or the local network. A url passed for ONE call
    // (`using($provider, $model, ['url' => ...])`) may not point at a loopback,
    // private, link-local or metadata address unless that provider's
    // `allow_private_per_call_url` is true. Off by default; a per-call value
    // cannot turn it on.
    'providers' => [
        'openai' => [
            'allow_private_per_call_url' => env('OPENAI_ALLOW_PRIVATE_PER_CALL_URL', false),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY', ''),
            'organization' => env('OPENAI_ORGANIZATION', null),
            'project' => env('OPENAI_PROJECT', null),
            'api_format' => env('OPENAI_API_FORMAT', 'responses'),
        ],
        'anthropic' => [
            'allow_private_per_call_url' => env('ANTHROPIC_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
            // Include beta strings as a comma separated list.
            'anthropic_beta' => env('ANTHROPIC_BETA', null),
        ],
        'ollama' => [
            'allow_private_per_call_url' => env('OLLAMA_ALLOW_PRIVATE_PER_CALL_URL', false),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
        'mistral' => [
            'allow_private_per_call_url' => env('MISTRAL_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('MISTRAL_API_KEY', ''),
            'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
        ],
        'groq' => [
            'allow_private_per_call_url' => env('GROQ_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('GROQ_API_KEY', ''),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
        ],
        'xai' => [
            'allow_private_per_call_url' => env('XAI_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('XAI_API_KEY', ''),
            'url' => env('XAI_URL', 'https://api.x.ai/v1'),
        ],
        'gemini' => [
            'allow_private_per_call_url' => env('GEMINI_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('GEMINI_API_KEY', ''),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        ],
        'deepseek' => [
            'allow_private_per_call_url' => env('DEEPSEEK_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1'),
        ],
        'elevenlabs' => [
            'allow_private_per_call_url' => env('ELEVENLABS_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('ELEVENLABS_API_KEY', ''),
            'url' => env('ELEVENLABS_URL', 'https://api.elevenlabs.io/v1/'),
        ],
        'voyageai' => [
            'allow_private_per_call_url' => env('VOYAGEAI_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('VOYAGEAI_API_KEY', ''),
            'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
        ],
        'openrouter' => [
            'allow_private_per_call_url' => env('OPENROUTER_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('OPENROUTER_API_KEY', ''),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
            'site' => [
                'http_referer' => env('OPENROUTER_SITE_HTTP_REFERER', null),
                'x_title' => env('OPENROUTER_SITE_X_TITLE', null),
            ],
        ],
        'replicate' => [
            'allow_private_per_call_url' => env('REPLICATE_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('REPLICATE_API_KEY', ''),
            'url' => env('REPLICATE_URL', 'https://api.replicate.com/v1'),
            'webhook_url' => env('REPLICATE_WEBHOOK_URL', null),
            'use_sync_mode' => env('REPLICATE_USE_SYNC_MODE', true), // Use Prefer: wait header
            'polling_interval' => env('REPLICATE_POLLING_INTERVAL', 1000),
            'max_wait_time' => env('REPLICATE_MAX_WAIT_TIME', 60),
            // Hosts generated images/audio may be downloaded from (HTTPS, port 443;
            // includes subdomains). Downloads omit API credentials and refuse redirects.
            'download_hosts' => ['replicate.delivery'],
        ],
        'qwen' => [
            'allow_private_per_call_url' => env('QWEN_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('QWEN_API_KEY', ''),
            'url' => env('QWEN_URL', 'https://dashscope-intl.aliyuncs.com/api/v1'),
        ],
        'azure' => [
            'allow_private_per_call_url' => env('AZURE_AI_ALLOW_PRIVATE_PER_CALL_URL', false),
            'url' => env('AZURE_AI_URL', ''),
            'api_key' => env('AZURE_AI_API_KEY', ''),
            'api_version' => env('AZURE_AI_API_VERSION', '2024-10-21'),
            'deployment_name' => env('AZURE_AI_DEPLOYMENT', ''),
        ],
        'requesty' => [
            'allow_private_per_call_url' => env('REQUESTY_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('REQUESTY_API_KEY', ''),
            'url' => env('REQUESTY_URL', 'https://router.requesty.ai/v1'),
            'site' => [
                'http_referer' => env('REQUESTY_SITE_HTTP_REFERER', null),
                'x_title' => env('REQUESTY_SITE_X_TITLE', null),
            ],
        ],
        'perplexity' => [
            'allow_private_per_call_url' => env('PERPLEXITY_ALLOW_PRIVATE_PER_CALL_URL', false),
            'api_key' => env('PERPLEXITY_API_KEY', ''),
            'url' => env('PERPLEXITY_URL', 'https://api.perplexity.ai'),
            // Deep research can take several minutes. Per-request client
            // options still take precedence. No automatic retries are added.
            'deep_research_timeout' => (int) env('PERPLEXITY_DEEP_RESEARCH_TIMEOUT', 600),
        ],
        'vertex' => [
            'allow_private_per_call_url' => env('VERTEX_ALLOW_PRIVATE_PER_CALL_URL', false),
            'project_id' => env('VERTEX_PROJECT_ID', ''),
            'region' => env('VERTEX_REGION', 'us-central1'),
            'access_token' => env('VERTEX_ACCESS_TOKEN', null),
            'credentials_path' => env('VERTEX_CREDENTIALS_PATH', null),
        ],
        'z' => [
            'allow_private_per_call_url' => env('Z_ALLOW_PRIVATE_PER_CALL_URL', false),
            'url' => env('Z_URL', 'https://api.z.ai/api/paas/v4'),
            'api_key' => env('Z_API_KEY', ''),
        ],
    ],
];
