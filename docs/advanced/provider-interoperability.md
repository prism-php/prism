# Provider Interoperability

Provider capabilities and options differ. Use conditional configuration when a request needs provider-specific settings.

## Using the `whenProvider` Method

`whenProvider` applies a callback only when the request uses the specified provider.

```php
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withPrompt('Who are you?')
    ->whenProvider(
        Provider::Anthropic,
        fn ($request) => $request
            ->withProviderOptions([
                'cacheType' => 'ephemeral',
            ])
    )
    ->asText();
```

In this example, the `withProviderOptions` settings will only be applied when using Anthropic's provider. If you're using OpenAI (as specified in the `using` method), these customizations are simply skipped.

## Advanced Usage

You can chain multiple `whenProvider` calls to handle different provider scenarios:

```php
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withPrompt('Generate a creative story about robots.')
    ->whenProvider(
        Provider::Anthropic,
        fn ($request) => $request
            ->withMaxTokens(4000)
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    )
    ->whenProvider(
        Provider::OpenAI,
        fn ($request) => $request
            ->withMaxTokens(2000)
            ->withProviderOptions(['response_format' => ['type' => 'text']])
    )
    ->asText();
```

## Using Invokable Classes

For more complex provider-specific configurations, you can use invokable classes instead of closures:

```php
class AnthropicConfigurator
{
    public function __invoke($request)
    {
        return $request
            ->withMaxTokens(4000)
            ->withProviderOptions([
                'cacheType' => 'ephemeral',
                'citations' => true,
            ]);
    }
}

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withPrompt('Explain the theory of relativity.')
    ->whenProvider(Provider::Anthropic, new AnthropicConfigurator())
    ->asText();
```

This approach can be especially helpful when you have complex or reusable provider configurations.

> [!TIP]
> The `whenProvider` method works with all request types in Prism including text, structured output, and embeddings requests.

## Best Practices

### Avoiding SystemMessages with Multiple Providers

When working with multiple providers, it's best to avoid using `SystemMessages` directly in your `withMessages` array. Instead, use the `withSystemPrompt` method as it offers better provider interoperability.

```php
// Avoid this when switching between providers
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withMessages([
        new SystemMessage('You are a helpful assistant.'),
        new UserMessage('Tell me about AI'),
    ])
    ->asText();

// Prefer this instead
$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4o')
    ->withSystemPrompt('You are a helpful assistant.')
    ->withPrompt('Tell me about AI')
    ->asText();
```

This approach allows Prism to handle the provider-specific formatting of system messages, making your code more portable across different LLM providers.
