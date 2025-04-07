# Citations and Source References

Many LLM providers now offer citation capabilities that allow the model to reference source materials in its responses. Prism provides a unified abstraction for working with citations across different providers.

## Supported Providers

Currently, the following providers support citations in Prism:

- **Anthropic** - Using Claude's native citation format
- **Gemini** - Using Gemini's search groundings feature

Support for more providers like Perplexity will be added as they become available.

## Accessing Citations

When using a provider that supports citations, the citations will be included in the response's `additionalContent` array under the `citations` key:

```php
$response = Prism::text()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withMessages([
        (new UserMessage(
            content: 'What color is the grass and sky?',
            additionalContent: [
                Document::fromText('The grass is green. The sky is blue.'),
            ]
        )),
    ])
    ->withProviderMeta(Provider::Anthropic, ['citations' => true])
    ->generate();

// Access the unified citation format
$citations = $response->additionalContent['citations'] ?? [];

foreach ($citations as $part) {
    echo "Text: {$part->text}\n";
    foreach ($part->citations as $citation) {
        echo "  Source: {$citation->sourceTitle}\n";
        echo "  Text: {$citation->text}\n";
    }
}
```

## Citation Value Objects

Prism's citation abstraction uses two main value objects:

### Citation

A single citation representing a reference to a source document:

```php
$citation = new Citation(
    text: 'The grass is green.',      // The cited text
    startPosition: 0,                 // Start position in the response
    endPosition: 20,                  // End position in the response
    sourceIndex: 0,                   // Source document index
    sourceTitle: 'Nature facts',      // Source document title (optional)
    sourceUrl: 'https://example.com', // Source URL (optional)
    confidence: 0.97,                 // Confidence score (optional)
    type: 'page'                      // Citation type (optional)
);
```

### MessagePartWithCitations

A segment of the response text with associated citations:

```php
$messagePart = new MessagePartWithCitations(
    text: 'The grass is green and the sky is blue.',
    citations: [$citation1, $citation2],  // Array of Citation objects
    startPosition: 0,                     // Start position of this segment (optional)
    endPosition: 38                       // End position of this segment (optional)
);
```

## Provider-Specific Configuration

### Anthropic

To enable citations with Anthropic's Claude models, use the `citations` provider meta option:

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withMessages([
        (new UserMessage(
            content: 'What do these documents say?',
            additionalContent: [
                Document::fromText('The grass is green. The sky is blue.'),
            ]
        )),
    ])
    ->withProviderMeta(Provider::Anthropic, ['citations' => true])
    ->generate();
```

### Gemini

Gemini's search groundings are automatically mapped to the unified citation format when they're present in the response:

```php
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-pro')
    ->withPrompt('What is the current stock price of Google?')
    ->generate();

// If search groundings are available, they'll be mapped to citations
$citations = $response->additionalContent['citations'] ?? [];
```

## Provider-Specific Citation Formats

Each provider's native citation format is still available for backwards compatibility:

### Anthropic

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    // ...configuration...
    ->generate();

// Access Anthropic's native citations
$anthropicCitations = $response->additionalContent['messagePartsWithCitations'] ?? [];
```

### Gemini

```php
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-pro')
    // ...configuration...
    ->generate();

// Access Gemini's native search groundings
$searchGroundings = $response->additionalContent['groundingSupports'] ?? [];
```