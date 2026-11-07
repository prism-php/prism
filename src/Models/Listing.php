<?php

declare(strict_types=1);

namespace Prism\Prism\Models;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use JsonException;
use Prism\Prism\Exceptions\ModelListingException;
use stdClass;

/** @internal Provider transport mapping; no cache, prices or model selection policy. */
class Listing
{
    public function __construct(protected PendingRequest $client) {}

    /**
     * @param  'openai'|'anthropic'|'gemini'|'ollama'|'elevenlabs'|'replicate'|'qwen'  $format
     * @return list<Model>
     */
    public function all(
        string $format = 'openai',
        string $endpoint = 'models',
        ?string $nameKey = null,
        ?string $createdKey = 'created',
    ): array {
        $models = [];
        $ids = [];
        $cursors = [];
        $query = match ($format) {
            'anthropic' => ['limit' => 1000],
            'gemini' => ['pageSize' => 1000],
            'qwen' => ['page_no' => 1, 'page_size' => 100],
            default => [],
        };

        for ($page = 1; $page <= 1000; $page++) {
            $data = $this->fetch($endpoint, $query);
            $rows = match ($format) {
                'gemini', 'ollama' => data_get($data, 'models'),
                'elevenlabs' => $data,
                'replicate' => data_get($data, 'results'),
                'qwen' => data_get($data, 'output.models'),
                default => data_get($data, 'data'),
            };

            if (! is_array($rows) || ! array_is_list($rows)) {
                throw ModelListingException::invalidResponse();
            }

            foreach ($rows as $row) {
                if (! $row instanceof stdClass) {
                    throw ModelListingException::invalidResponse();
                }

                $model = $this->map($row, $format, $nameKey, $createdKey);

                if (isset($ids[$model->id])) {
                    throw ModelListingException::invalidResponse();
                }

                $ids[$model->id] = true;
                $models[] = $model;
            }

            $next = $this->nextQuery($format, $data, $endpoint, $query, $rows, count($models));

            if ($next === null) {
                return $models;
            }

            $cursor = json_encode($next, JSON_THROW_ON_ERROR);

            if ($rows === [] || isset($cursors[$cursor])) {
                throw ModelListingException::invalidResponse();
            }

            $cursors[$cursor] = true;
            $query = $next;
        }

        // Never report a truncated catalogue as a successful complete listing.
        throw ModelListingException::invalidResponse();
    }

    /** @param array<string, int|string> $query */
    protected function fetch(string $endpoint, array $query): mixed
    {
        try {
            $response = (clone $this->client)->withOptions(['allow_redirects' => false])->get($endpoint, $query);

            if (in_array($response->status(), [401, 403], true)) {
                throw ModelListingException::authenticationFailed();
            }

            if (! $response->successful()) {
                throw ModelListingException::requestFailed();
            }

            $data = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);

            if ($data instanceof stdClass && isset($data->error)) {
                throw ModelListingException::invalidResponse();
            }

            return $data;
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [401, 403], true)) {
                throw ModelListingException::authenticationFailed();
            }

            throw ModelListingException::requestFailed();
        } catch (ConnectionException) {
            // No previous exception: its request/body may carry provider secrets.
            throw ModelListingException::requestFailed();
        } catch (JsonException) {
            throw ModelListingException::invalidResponse();
        }
    }

    protected function map(stdClass $row, string $format, ?string $nameKey, ?string $createdKey): Model
    {
        $idKey = match ($format) {
            'gemini', 'ollama' => 'name',
            'elevenlabs' => 'model_id',
            'qwen' => 'model',
            default => 'id',
        };
        $id = $format === 'replicate'
            ? $this->identifier(data_get($row, 'owner')).'/'.$this->identifier(data_get($row, 'name'))
            : $this->identifier(data_get($row, $idKey));

        if ($format === 'gemini') {
            if (! str_starts_with($id, 'models/')) {
                throw ModelListingException::invalidResponse();
            }

            $id = $this->identifier(substr($id, 7));
        }

        $nameKey = match ($format) {
            'anthropic' => 'display_name',
            'gemini' => 'displayName',
            'elevenlabs', 'qwen' => 'name',
            default => $nameKey,
        };
        $createdKey = match ($format) {
            'anthropic' => 'created_at',
            'qwen' => 'published_time',
            'gemini', 'ollama', 'elevenlabs', 'replicate' => null,
            default => $createdKey,
        };
        $name = $nameKey === null ? null : data_get($row, $nameKey);
        $created = $createdKey === null ? null : data_get($row, $createdKey);

        if (($name !== null && ! is_string($name)) ||
            ($created !== null && ! is_string($created) && ! is_int($created))) {
            throw ModelListingException::invalidResponse();
        }

        return new Model($id, $name, $created);
    }

    protected function identifier(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') ||
            trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw ModelListingException::invalidResponse();
        }

        return $value;
    }

    /**
     * @param  array<string, int|string>  $query
     * @param  list<stdClass>  $rows
     * @return array<string, int|string>|null
     */
    protected function nextQuery(string $format, mixed $data, string $endpoint, array $query, array $rows, int $count): ?array
    {
        if ($format === 'openai' && (
            ($data instanceof stdClass && property_exists($data, 'has_more') && $data->has_more !== false) ||
            data_get($data, 'next') !== null || data_get($data, 'links.next') !== null
        )) {
            // This mapping has no documented pagination contract; never return a partial list or follow a URL.
            throw ModelListingException::invalidResponse();
        }

        if ($format === 'anthropic') {
            $more = data_get($data, 'has_more');

            if (! is_bool($more)) {
                throw ModelListingException::invalidResponse();
            }

            if (! $more) {
                return null;
            }

            $cursor = $this->identifier(data_get($data, 'last_id'));

            if ($rows === [] || data_get($rows[array_key_last($rows)], 'id') !== $cursor) {
                throw ModelListingException::invalidResponse();
            }

            return ['limit' => 1000, 'after_id' => $cursor];
        }

        if ($format === 'gemini') {
            $cursor = data_get($data, 'nextPageToken');

            if ($cursor === null || $cursor === '') {
                return null;
            }

            return ['pageSize' => 1000, 'pageToken' => $this->identifier($cursor)];
        }

        if ($format === 'qwen') {
            $total = data_get($data, 'output.total');

            if (data_get($data, 'success') !== true || ! is_int($total) || $total < $count ||
                data_get($data, 'output.page_no') !== $query['page_no']) {
                throw ModelListingException::invalidResponse();
            }

            return $count === $total ? null : ['page_no' => ((int) $query['page_no']) + 1, 'page_size' => 100];
        }

        if ($format === 'replicate') {
            if (! $data instanceof stdClass || ! property_exists($data, 'next')) {
                throw ModelListingException::invalidResponse();
            }

            if ($data->next === null) {
                return null;
            }

            return ['cursor' => $this->replicateCursor($data->next, $endpoint)];
        }

        return null;
    }

    protected function replicateCursor(mixed $next, string $endpoint): string
    {
        if (! is_string($next)) {
            throw ModelListingException::invalidResponse();
        }

        $expected = parse_url($endpoint);
        $actual = parse_url($next);

        if (! is_array($expected) || ! is_array($actual) ||
            array_diff_key($actual, array_flip(['scheme', 'host', 'port', 'path', 'query'])) !== []) {
            throw ModelListingException::invalidResponse();
        }

        foreach (['scheme', 'host', 'port', 'path'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw ModelListingException::invalidResponse();
            }
        }

        if (! preg_match('/\Acursor=([^&]+)\z/', $actual['query'] ?? '', $matches)) {
            throw ModelListingException::invalidResponse();
        }

        // Send only the cursor to the original configured endpoint, never follow a URL.
        return $this->identifier(urldecode($matches[1]));
    }
}
