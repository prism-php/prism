<?php

declare(strict_types=1);

namespace Prism\Prism;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Providers\Azure\Azure;
use Prism\Prism\Providers\DeepSeek\DeepSeek;
use Prism\Prism\Providers\ElevenLabs\ElevenLabs;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\Providers\Groq\Groq;
use Prism\Prism\Providers\Mistral\Mistral;
use Prism\Prism\Providers\Ollama\Ollama;
use Prism\Prism\Providers\OpenAI\OpenAI;
use Prism\Prism\Providers\OpenRouter\OpenRouter;
use Prism\Prism\Providers\Perplexity\Perplexity;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Qwen\Qwen;
use Prism\Prism\Providers\Replicate\Replicate;
use Prism\Prism\Providers\Requesty\Requesty;
use Prism\Prism\Providers\Vertex\Vertex;
use Prism\Prism\Providers\VoyageAI\VoyageAI;
use Prism\Prism\Providers\XAI\XAI;
use Prism\Prism\Providers\Z\Z;

class PrismManager
{
    /**
     * Configuration keys that authenticate the caller. They are scoped to the
     * endpoint they were configured for.
     *
     * @var list<string>
     */
    protected const CREDENTIAL_KEYS = ['api_key', 'access_token', 'credentials_path', 'organization', 'project'];

    /** @var array<string, Closure> */
    protected array $customCreators = [];

    /**
     * A provider name: ASCII, starting with a letter or digit, at most 64
     * characters. A positive allow-list rather than a list of forbidden
     * characters, so it needs no Unicode tables and reads the same in prism-ts
     * registerProvider() and prism-py register_provider().
     */
    protected const PROVIDER_NAME = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /**
     * Codepoints a provider url may not contain anywhere, spelled by codepoint
     * and identically in prism-ts and prism-py: every Cc control
     * (U+0000..U+001F, U+007F..U+009F), every Unicode White_Space codepoint,
     * and the G-36 zero-widths U+200B..U+200D and U+FEFF. Each language's own
     * idea of "whitespace" disagrees with the others, so none of them is used.
     */
    protected const URL_FORBIDDEN = '/[\x{0000}-\x{001F}\x{0020}\x{007F}-\x{009F}\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u';

    public function __construct(
        protected Application $app
    ) {}

    /**
     * @param  array<string, mixed>  $providerConfig
     *
     * @throws InvalidArgumentException
     */
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        $name = $this->resolveName($name);

        $configured = $this->getConfig($name);

        // Both urls are checked, before anything is built: a padded configured
        // url is a misconfiguration even when this call overrides it.
        $this->assertUrlIsClean($configured['url'] ?? null);
        $this->assertUrlIsClean($providerConfig['url'] ?? null);

        $config = $this->mergeConfig($configured, $providerConfig);

        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($name, $config);
        }

        $factory = sprintf('create%sProvider', ucfirst($name));

        if (method_exists($this, $factory)) {
            return $this->{$factory}($config);
        }

        throw new InvalidArgumentException("Provider [{$name}] is not supported.");
    }

    /**
     * Register a custom provider creator.
     *
     * A name that is already taken — a built-in provider, or a custom provider
     * registered earlier — is refused unless `$replace` is true. Replacing a
     * provider changes what every caller of that name gets, process-wide, and
     * hands the replacement that provider's configured credentials; that must
     * be a decision made on purpose, not a side effect of a naming collision.
     *
     * The name itself must be readable: see isValidProviderName(). An invalid
     * name is refused whether or not `$replace` is set, and never trimmed.
     *
     * @throws InvalidArgumentException when the name is not a valid provider
     *                                  name, or is taken and $replace is false
     */
    public function extend(string $provider, Closure $callback, bool $replace = false): self
    {
        if (! $this->isValidProviderName($provider)) {
            throw new InvalidArgumentException(
                'That is not a valid provider name: a provider name must start with an ASCII letter or digit, contain only ASCII letters, digits, ".", "_" and "-", and be at most 64 characters long.'
            );
        }

        if (! $replace && $this->isRegistered($provider)) {
            throw new InvalidArgumentException(
                "Provider [{$provider}] is already registered. Pass replace: true to replace it."
            );
        }

        $this->customCreators[$provider] = $callback;

        return $this;
    }

    protected function isRegistered(string $provider): bool
    {
        $name = strtolower($provider);

        foreach (array_keys($this->customCreators) as $registered) {
            if (strtolower($registered) === $name) {
                return true;
            }
        }

        return method_exists($this, sprintf('create%sProvider', ucfirst($name)));
    }

    /**
     * Merge per-call provider config over the configured provider config.
     *
     * Credentials are scoped to their configured endpoint: when the per-call
     * config points the provider at a different URL, configured credentials
     * are not carried over. The per-call config must supply its own key for
     * that endpoint. A URL that differs only in a trailing slash or in the case
     * of the scheme or host is the same endpoint.
     *
     * @param  array<string, mixed>  $configured
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function mergeConfig(array $configured, array $overrides): array
    {
        $config = array_merge($configured, $overrides);

        if (! array_key_exists('url', $overrides)) {
            return $config;
        }

        if ($this->sameEndpoint($configured['url'] ?? null, $overrides['url'])) {
            return $config;
        }

        foreach (self::CREDENTIAL_KEYS as $key) {
            if (! array_key_exists($key, $overrides)) {
                unset($config[$key]);
            }
        }

        return $config;
    }

    protected function sameEndpoint(mixed $configured, mixed $override): bool
    {
        if (! is_string($configured) || ! is_string($override)) {
            return false;
        }

        $a = $this->normalizeEndpoint($configured);
        $b = $this->normalizeEndpoint($override);

        return $a !== null && $a === $b;
    }

    protected function normalizeEndpoint(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return implode('|', [
            $scheme,
            $parts['user'] ?? '',
            $parts['pass'] ?? '',
            strtolower($parts['host']),
            (string) $port,
            rtrim($parts['path'] ?? '', '/'),
            $parts['query'] ?? '',
        ]);
    }

    protected function resolveName(ProviderEnum|string $name): string
    {
        if ($name instanceof ProviderEnum) {
            $name = $name->value;
        }

        return strtolower($name);
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createOpenaiProvider(array $config): OpenAI
    {
        return new OpenAI(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
            organization: $config['organization'] ?? null,
            project: $config['project'] ?? null,
            apiFormat: $config['api_format'] ?? 'responses',
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createOllamaProvider(array $config): Ollama
    {
        return new Ollama(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createMistralProvider(array $config): Mistral
    {
        return new Mistral(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createAnthropicProvider(array $config): Anthropic
    {
        return new Anthropic(
            apiKey: $config['api_key'] ?? '',
            apiVersion: $config['version'],
            url: $config['url'] ?? 'https://api.anthropic.com/v1',
            betaFeatures: $config['anthropic_beta'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createDeepseekProvider(array $config): DeepSeek
    {
        return new DeepSeek(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? '',
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createVoyageaiProvider(array $config): VoyageAI
    {
        return new VoyageAI(
            apiKey: $config['api_key'] ?? '',
            baseUrl: $config['url'] ?? ''
        );
    }

    /**
     * @param  array<string, string|null>  $config
     */
    protected function createPerplexityProvider(array $config): Perplexity
    {
        return new Perplexity(apiKey: $config['api_key'] ?? '', url: $config['url'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function callCustomCreator(string $provider, array $config): Provider
    {
        return $this->customCreators[$provider]($this->app, $config);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        return config("prism.providers.{$name}", []);
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createGroqProvider(array $config): Groq
    {
        return new Groq(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createXaiProvider(array $config): XAI
    {
        return new XAI(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createGeminiProvider(array $config): Gemini
    {
        return new Gemini(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createOpenrouterProvider(array $config): OpenRouter
    {
        $siteConfig = $config['site'] ?? null;
        $site = is_array($siteConfig) ? $siteConfig : [];

        return new OpenRouter(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://openrouter.ai/api/v1',
            httpReferer: $site['http_referer'] ?? null,
            xTitle: $site['x_title'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createRequestyProvider(array $config): Requesty
    {
        $siteConfig = $config['site'] ?? null;
        $site = is_array($siteConfig) ? $siteConfig : [];

        return new Requesty(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://router.requesty.ai/v1',
            httpReferer: $site['http_referer'] ?? null,
            xTitle: $site['x_title'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createAzureProvider(array $config): Azure
    {
        return new Azure(
            url: $config['url'] ?? '',
            apiKey: $config['api_key'] ?? '',
            apiVersion: $config['api_version'] ?? '2024-10-21',
            deploymentName: $config['deployment_name'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createElevenlabsProvider(array $config): ElevenLabs
    {
        return new ElevenLabs(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://api.elevenlabs.io/v1/',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createReplicateProvider(array $config): Replicate
    {
        return new Replicate(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://api.replicate.com/v1',
            webhookUrl: $config['webhook_url'] ?? null,
            useSyncMode: $config['use_sync_mode'] ?? true,
            pollingInterval: $config['polling_interval'] ?? 1000,
            maxWaitTime: $config['max_wait_time'] ?? 60,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createQwenProvider(array $config): Qwen
    {
        return new Qwen(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'] ?? 'https://dashscope-intl.aliyuncs.com/api/v1',
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createVertexProvider(array $config): Vertex
    {
        return new Vertex(
            projectId: $config['project_id'] ?? '',
            region: $config['region'] ?? 'us-central1',
            accessToken: $config['access_token'] ?? null,
            credentialsPath: $config['credentials_path'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function createZProvider(array $config): Z
    {
        return new Z(
            apiKey: $config['api_key'] ?? '',
            url: $config['url'],
        );
    }

    /**
     * Is this a name a provider may be registered under?
     *
     * See PROVIDER_NAME. A homoglyph (Cyrillic U+043E for the first `o`), a
     * full-width `openai`, a padded one or one carrying an invisible codepoint
     * is a second key that reads as the built-in in a config file or a log, so
     * it is REFUSED, never trimmed or folded. Bytes that are not ASCII never
     * match, so invalid UTF-8 is refused too.
     */
    protected function isValidProviderName(string $provider): bool
    {
        return preg_match(self::PROVIDER_NAME, $provider) === 1;
    }

    /**
     * Refuse a url that carries whitespace or a control character anywhere.
     *
     * Trimming it would leave the endpoint comparison, and where the key goes,
     * to each HTTP stack's tolerance of a padded url, which differs by library
     * version. The message names neither the url nor any credential. A string
     * that is not valid UTF-8 cannot be checked, so it is refused as well
     * (preg_match returns false).
     *
     * @throws InvalidArgumentException
     */
    protected function assertUrlIsClean(mixed $url): void
    {
        if (! is_string($url)) {
            return;
        }

        if (preg_match(self::URL_FORBIDDEN, $url) !== 0) {
            throw new InvalidArgumentException(
                'That is not a valid provider url: a provider url must not contain whitespace or control characters.'
            );
        }
    }
}
