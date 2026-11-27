# Model listing

List the models returned by a provider's API using its normal Prism configuration:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$models = Prism::provider(Provider::Anthropic)->listModels();

foreach ($models as $model) {
    echo $model->id;
    echo $model->displayName ?? $model->id;
}
```

Each `Prism\Prism\Models\Model` contains an `id`, an optional `displayName`, and
an optional `createdAt`. Creation timestamps retain the provider's string or
integer representation. Missing names and dates are `null`. Model identifiers
use the provider's calling convention: Gemini removes the `models/` resource
prefix, and Replicate combines its returned owner and model name as `owner/name`.

Prism returns the listing without selecting models, inferring capabilities,
adding prices, or caching results. A listed model can still require additional
permissions, a particular endpoint, or a supported Prism capability to invoke.
Replicate lists its public model catalogue; it does not enumerate private models.

## Provider support

| Provider | Endpoint | Pagination |
| --- | --- | --- |
| Anthropic | [`GET /v1/models`](https://platform.claude.com/docs/en/api/models/list) | `after_id` until `has_more` is false |
| OpenAI | [`GET /v1/models`](https://developers.openai.com/api/reference/resources/models/methods/list) | Complete response |
| Azure | [`GET /openai/v1/models`](https://learn.microsoft.com/en-us/rest/api/aifoundry/azureopenai/models) | Complete response; configure a URL ending in `/openai/v1` |
| DeepSeek | [`GET /models`](https://api-docs.deepseek.com/api/list-models/) | Complete response |
| Groq | [`GET /openai/v1/models`](https://console.groq.com/docs/api-reference#models-list) | Complete response |
| Mistral | [`GET /v1/models`](https://docs.mistral.ai/api/endpoint/models) | Complete response |
| xAI | [`GET /v1/models`](https://docs.x.ai/developers/rest-api-reference/inference/models) | Complete response |
| OpenRouter | [`GET /api/v1/models`](https://openrouter.ai/docs/api/api-reference/models/list-all-models-and-their-properties) | Full list with both pagination parameters omitted |
| Requesty | [`GET /v1/models`](https://docs.requesty.ai/api-reference/endpoint/models-list) | Complete response |
| Gemini | [`GET /v1beta/models`](https://aistudio.google.com/api-reference/models/list-models) | `pageToken` until no next token remains |
| Ollama | [`GET /api/tags`](https://docs.ollama.com/api/tags) | Complete response |
| ElevenLabs | [`GET /v1/models`](https://elevenlabs.io/docs/api-reference/models/list) | Complete response |
| Replicate | [`GET /v1/models`](https://replicate.com/docs/reference/http#models.list) | Validated cursor from each next page |
| Qwen | [`GET /api/v1/models`](https://www.alibabacloud.com/help/en/model-studio/list-models) | `page_no` until the returned total is reached |

Perplexity, VoyageAI, Vertex and Z do not implement model listing. They raise
`Prism\Prism\Exceptions\UnsupportedModelListingException` before making a
listing request. Azure's legacy deployment URLs raise the same exception.
Vertex's separate Model Garden catalogue is not a list of the configured
project's callable regional models.

## Client options and failures

```php
use Prism\Prism\Models\ListModelsRequest;

$request = (new ListModelsRequest)->withClientOptions(['timeout' => 15]);
$models = Prism::provider(Provider::OpenAI)->listModels($request);
```

Provider configuration overrides remain available through
`Prism::provider($provider, $providerConfig)`. Keep credentials on the server.
Listing requests refuse HTTP redirects. Replicate pagination sends only the
validated cursor to the original configured endpoint.

HTTP 401 and 403 raise
`Prism\Prism\Exceptions\ModelListingAuthenticationException`, a subclass of
`ModelListingException`. Update the provider credentials or access permissions
before retrying. This exception uses a fixed message and does not retain the
provider's response body, request URL or underlying exception.

`Prism\Prism\Exceptions\ModelListingException` reports an HTTP or connection
failure, invalid response, duplicate model identifier, broken pagination or a
listing exceeding 1,000 pages. It does not retain the provider's response body,
request URL or underlying exception. A failed listing never returns earlier
pages as a complete result. An explicitly empty successful list returns `[]`.
OpenAI-shaped mappings with no pagination contract reject `has_more` unless it
is `false`, and reject non-null `next` or `links.next` values. They do not follow
those links or return the first page as a complete listing.
