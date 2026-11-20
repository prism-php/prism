# Provider Interoperability

Provider capabilities, models and option names differ. Select a model supported by the chosen provider, then apply only options that integration maps.

## Using the `whenProvider` method

`whenProvider()` compares the selected provider key to a string or `Provider` enum and runs the callback only on a match. Return the builder from the callback:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->whenProvider(
        Provider::Anthropic,
        fn ($request) => $request->withProviderOptions(['effort' => 'medium'])
    )
    ->withPrompt('Explain database indexes.')
    ->asText();
```

Use lower-case provider keys consistently. `whenProvider()` compares keys exactly; it does not translate a model ID when you change providers.

## Conditional request controls

Apply multiple conditions when the provider and model are chosen by your application:

```php
use Prism\Prism\Facades\Prism;

// $provider and $model are an application-approved provider/model pair.
$response = Prism::text()
    ->using($provider, $model)
    ->whenProvider('anthropic', fn ($request) => $request
        ->withMaxTokens(4000)
        ->withProviderOptions(['effort' => 'medium']))
    ->whenProvider('openai', fn ($request) => $request
        ->withMaxTokens(2000)
        ->withProviderOptions(['store' => false]))
    ->withPrompt('Explain database indexes.')
    ->asText();
```

`withProviderOptions()` replaces its array. Combine options in one call when they must coexist. Consult the [Anthropic](/providers/anthropic) and [OpenAI](/providers/openai) guides for each option's supported models and API format.

## Using invokable classes

An invokable configurator can share conditional settings across requests:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\PendingRequest;

class AnthropicConfigurator
{
    public function __invoke(PendingRequest $request): PendingRequest
    {
        return $request
            ->withMaxTokens(4000)
            ->withProviderOptions(['effort' => 'medium']);
    }
}

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->whenProvider('anthropic', new AnthropicConfigurator)
    ->withPrompt('Explain the theory of relativity.')
    ->asText();
```

The method is available on builders using provider configuration, including structured and embeddings requests. Match a configurator's parameter type to the builder it receives.

## Messages and media

Use `withSystemPrompt()` for instructions and `withMessages()` for conversation turns; system-message mapping inside the conversation varies by integration. A shared builder method does not guarantee provider or model support for an input.

Per-message cache options belong on messages, and per-tool strictness belongs on tools. Document citation options require a document and a provider that maps them. Putting those keys into an unrelated request option array does not enable the feature.

File IDs and provider replay metadata belong to their originating provider. When changing providers, rebuild history in a supported form instead of forwarding provider-specific reasoning items, signed thinking blocks or file IDs blindly. See [input modalities](/input-modalities/documents) and each provider's guide before moving a multimodal conversation.
