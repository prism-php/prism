<?php

declare(strict_types=1);

namespace Prism\Prism\Http;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Prism\Prism\Http\Exceptions\ConnectionException;
use Psr\Http\Message\StreamInterface;

class PendingRequest
{
    use Conditionable;
    use Macroable;

    protected Factory $factory;

    protected Client $client;

    protected string $baseUrl = '';

    protected array $urlParameters = [];

    protected string $bodyFormat = 'json';

    protected StreamInterface|string|null $pendingBody = null;

    protected array $pendingFiles = [];

    protected array $cookies = [];

    protected array $options = [
        'http_errors' => false,
        'connect_timeout' => 10,
        'timeout' => 30,
    ];

    protected int $tries = 1;

    protected Closure|int $retryDelay = 0;

    /** @var callable|null */
    protected $retryWhenCallback;

    protected bool $retryThrow = true;

    protected ?string $prismProvider = null;

    protected array $prismOptions = [];

    protected ?int $rateLimit = null;

    public function __construct(?Factory $factory = null, protected array $middleware = [])
    {
        $this->factory = $factory ?: new Factory;
        $this->asJson();
    }

    public function baseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');

        return $this;
    }

    public function withBody(StreamInterface|string $content, string $contentType = 'application/json'): static
    {
        $this->bodyFormat = 'body';
        $this->pendingBody = $content;

        return $this->contentType($contentType);
    }

    public function asJson(): static
    {
        return $this->bodyFormat('json')->contentType('application/json');
    }

    public function asForm(): static
    {
        return $this->bodyFormat('form')->contentType('application/x-www-form-urlencoded');
    }

    public function attach(string $name, string $contents = '', ?string $filename = null, array $headers = []): static
    {
        $this->asMultipart();

        $this->pendingFiles[] = array_filter([
            'name' => $name,
            'contents' => $contents,
            'headers' => $headers,
            'filename' => $filename,
        ]);

        return $this;
    }

    public function asMultipart(): static
    {
        return $this->bodyFormat('multipart');
    }

    public function bodyFormat(string $format): static
    {
        $this->bodyFormat = $format;

        return $this;
    }

    public function withQueryParameters(array $parameters): static
    {
        return $this->withOptions(['query' => array_merge($this->options['query'] ?? [], $parameters)]);
    }

    public function contentType(string $contentType): static
    {
        return $this->withHeaders(['Content-Type' => $contentType]);
    }

    public function acceptJson(): static
    {
        return $this->accept('application/json');
    }

    public function accept(string $contentType): static
    {
        return $this->withHeaders(['Accept' => $contentType]);
    }

    public function withHeaders(array $headers): static
    {
        return $this->withOptions(['headers' => array_merge($this->options['headers'] ?? [], $headers)]);
    }

    public function withHeader(string $name, string $value): static
    {
        return $this->withHeaders([$name => $value]);
    }

    public function replaceHeaders(array $headers): static
    {
        return $this->withOptions(['headers' => $headers]);
    }

    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withOptions(['auth' => [$username, $password]]);
    }

    public function withDigestAuth(string $username, string $password): static
    {
        return $this->withOptions(['auth' => [$username, $password, 'digest']]);
    }

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => trim($type.' '.$token)]);
    }

    public function withUserAgent(string $userAgent): static
    {
        return $this->withHeaders(['User-Agent' => $userAgent]);
    }

    public function withUrlParameters(array $parameters = []): static
    {
        $this->urlParameters = array_merge($this->urlParameters, $parameters);

        return $this;
    }

    public function withCookies(array $cookies, string $domain): static
    {
        $this->cookies = array_merge($this->cookies, [$domain => $cookies]);

        return $this;
    }

    public function maxRedirects(int $max): static
    {
        return $this->withOptions(['allow_redirects' => ['max' => $max]]);
    }

    public function withoutRedirecting(): static
    {
        return $this->withOptions(['allow_redirects' => false]);
    }

    public function withoutVerifying(): static
    {
        return $this->withOptions(['verify' => false]);
    }

    public function sink(string $to): static
    {
        return $this->withOptions(['sink' => $to]);
    }

    public function timeout(int|float $seconds): static
    {
        return $this->withOptions(['timeout' => $seconds]);
    }

    public function connectTimeout(int|float $seconds): static
    {
        return $this->withOptions(['connect_timeout' => $seconds]);
    }

    public function retry(array|int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true): static
    {
        $this->tries = is_array($times) ? count($times) + 1 : $times + 1;
        $this->retryDelay = $sleepMilliseconds;
        $this->retryWhenCallback = $when;
        $this->retryThrow = $throw;

        return $this;
    }

    public function withOptions(array $options): static
    {
        foreach ($options as $key => $value) {
            if (isset($this->options[$key]) && is_array($this->options[$key]) && is_array($value)) {
                $this->options[$key] = array_merge($this->options[$key], $value);
            } else {
                $this->options[$key] = $value;
            }
        }

        return $this;
    }

    public function withMiddleware(callable $middleware): static
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function withRequestMiddleware(callable $middleware): static
    {
        return $this->withMiddleware(Middleware::mapRequest($middleware));
    }

    public function withResponseMiddleware(callable $middleware): static
    {
        return $this->withMiddleware(Middleware::mapResponse($middleware));
    }

    public function withProvider(string $provider): static
    {
        $this->prismProvider = $provider;

        return $this;
    }

    public function withRateLimit(int $requestsPerMinute): static
    {
        $this->rateLimit = $requestsPerMinute;

        return $this;
    }

    public function withPrismOptions(array $options): static
    {
        $this->prismOptions = array_merge($this->prismOptions, $options);

        return $this;
    }

    public function get(string $url, array|string|null $query = null): Response
    {
        return $this->send('GET', $url, func_num_args() === 1 ? [] : ['query' => $query]);
    }

    public function head(string $url, array|string|null $query = null): Response
    {
        return $this->send('HEAD', $url, func_num_args() === 1 ? [] : ['query' => $query]);
    }

    public function post(string $url, array $data = []): Response
    {
        return $this->send('POST', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    public function patch(string $url, array $data = []): Response
    {
        return $this->send('PATCH', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    public function put(string $url, array $data = []): Response
    {
        return $this->send('PUT', $url, [
            $this->bodyFormat => $data,
        ]);
    }

    public function delete(string $url, array $data = []): Response
    {
        return $this->send('DELETE', $url, $data === [] ? [] : [
            $this->bodyFormat => $data,
        ]);
    }

    public function send(string $method, string $url, array $options = []): Response
    {
        $url = $this->expandUrl($url);

        if (isset($options[$this->bodyFormat])) {
            $options = $this->parseRequestData($method, $url, $options);
        }

        $this->pendingBody = null;
        $this->pendingFiles = [];

        return $this->makeRequest($method, $url, $options);
    }
    public function getOptions(): array
    {
        return $this->options;
    }

    protected function expandUrl(string $url): string
    {
        $url = ltrim($url, '/');

        if ($this->baseUrl !== '' && $this->baseUrl !== '0') {
            $url = $this->baseUrl.'/'.$url;
        }

        return $this->expandUrlParameters($url);
    }

    protected function expandUrlParameters(string $url): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', fn($matches) => $this->urlParameters[$matches[1]] ?? $matches[0], $url);
    }

    protected function parseRequestData(string $method, string $url, array $options): array
    {
        if ($this->bodyFormat === 'json') {
            $options['json'] = $options[$this->bodyFormat];
        } elseif ($this->bodyFormat === 'form') {
            $options['form_params'] = $options[$this->bodyFormat];
        } elseif ($this->bodyFormat === 'multipart') {
            $options['multipart'] = array_merge(
                $options[$this->bodyFormat] ?? [],
                $this->pendingFiles
            );
        } elseif ($this->bodyFormat === 'body') {
            $options['body'] = $this->pendingBody;
        }

        unset($options[$this->bodyFormat]);

        return $options;
    }

    protected function makeRequest(string $method, string $url, array $options): Response
    {
        return $this->attemptRequest($method, $url, $options);
    }

    protected function attemptRequest(string $method, string $url, array $options, int $attempt = 1): Response
    {
        $request = new Request($method, $url, $options);

        // Check for stubbed responses first
        $stubCallbacks = $this->factory->getStubCallbacks();
        if ($stubCallbacks->isNotEmpty()) {
            foreach ($stubCallbacks as $callback) {
                $stubResponse = $callback($request, $this->mergeOptions($options));
                if ($stubResponse !== null) {
                    $response = $stubResponse instanceof Response
                        ? $stubResponse
                        : new Response($stubResponse, $this->prismProvider, $this->prismOptions);

                    $this->factory->recordRequestResponsePair($request, $response);

                    return $response;
                }
            }
        }

        try {
            $guzzleResponse = $this->buildClient()->request($method, $url, $this->mergeOptions($options));
        } catch (ConnectException $e) {
            $exception = new ConnectionException($e->getMessage(), 0, $e);

            if ($attempt < $this->tries && $this->shouldRetry($exception, $attempt)) {
                $this->sleep($attempt);

                return $this->attemptRequest($method, $url, $options, $attempt + 1);
            }

            if ($this->retryThrow) {
                throw $exception;
            }

            $guzzleResponse = $e->hasResponse() ? $e->getResponse() : null;
        }

        $response = new Response($guzzleResponse, $this->prismProvider, $this->prismOptions);

        if ($this->tries > 1 && ! $response->successful() && $this->shouldRetry($response, $attempt) && $attempt < $this->tries) {
            $this->sleep($attempt);
            return $this->attemptRequest($method, $url, $options, $attempt + 1);
        }

        $this->factory->recordRequestResponsePair($request, $response);

        return $response;
    }

    protected function shouldRetry(mixed $exception, int $attempt): bool
    {
        if ($this->retryWhenCallback) {
            return call_user_func($this->retryWhenCallback, $exception, $attempt);
        }

        return $exception instanceof ConnectionException ||
               ($exception instanceof Response && $exception->serverError());
    }

    protected function sleep(int $attempt): void
    {
        $delay = $this->retryDelay instanceof Closure
            ? ($this->retryDelay)($attempt)
            : $this->retryDelay;

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    protected function buildClient(): Client
    {
        $handler = HandlerStack::create();

        foreach ($this->middleware as $middleware) {
            $handler->push($middleware);
        }

        return new Client(['handler' => $handler]);
    }

    protected function mergeOptions(array $options): array
    {
        return array_merge_recursive($this->options, $options);
    }
}
