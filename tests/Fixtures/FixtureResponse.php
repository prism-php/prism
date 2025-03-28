<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FixtureResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function fakeResponseSequence(
        string $requestPath,
        string $name,
        array $headers = [],
        int $status = 200,
        bool $forceRecording = false,
    ): void {
        $basePath = dirname(static::filePath($name));
        $pathInfo = pathinfo($name);
        $filename = $pathInfo['filename'];

        // Check if fixture files exist
        $fixtureFiles = is_dir($basePath)
            ? collect(scandir($basePath))
                ->filter(fn (string $file): int|false => preg_match('/^'.preg_quote($filename, '/').'-\d+/', $file))
                ->toArray()
            : [];

        // If no fixture files exist, automatically record the response
        if ($forceRecording || empty($fixtureFiles)) {
            $iterator = 0;

            Http::fake(function ($request) use ($requestPath, $name, &$iterator, $headers) {
                if ($requestPath === '*' || Str::contains($request->url(), $requestPath)) {
                    $iterator++;

                    // Prepare path for recording
                    $path = static::filePath("{$name}-{$iterator}.json");

                    if (! is_dir(dirname($path))) {
                        mkdir(dirname($path), recursive: true);
                    }

                    // Forward the request to the real API
                    $client = new \GuzzleHttp\Client;
                    $options = [
                        'headers' => $request->headers(),
                        'body' => $request->body(),
                    ];

                    $response = $client->request($request->method(), $request->url(), $options);
                    $responseBody = (string) $response->getBody();

                    // Save the response
                    file_put_contents($path, $responseBody);

                    // Return the response with user-specified headers if any
                    $responseHeaders = $headers ?: [];

                    return Http::response(
                        $responseBody,
                        $response->getStatusCode(),
                        $responseHeaders
                    );
                }

                return Http::response('{"error":"Not mocked"}', 404);
            });

            return;
        }

        // Use existing fixture files
        $responses = collect($fixtureFiles)
            ->map(fn ($filename): string => $basePath.'/'.$filename)
            ->map(fn ($filePath) => Http::response(
                file_get_contents($filePath),
                $status,
                $headers
            ));

        Http::fake([
            $requestPath => Http::sequence($responses->toArray()),
        ])->preventStrayRequests();
    }

    public static function fakeStreamResponses(string $requestPath, string $name, array $headers = []): void
    {
        $basePath = dirname(static::filePath("{$name}-1.sse"));

        // Find all recorded .sse files for this test
        $files = collect(is_dir($basePath) ? scandir($basePath) : [])
            ->filter(fn ($file): int|false => preg_match('/^'.preg_quote(basename($name), '/').'-\d+\.sse$/', $file))
            ->map(fn ($file): string => $basePath.'/'.$file)
            ->values()
            ->toArray();

        // If no files exist, automatically record the streaming responses
        if (empty($files)) {
            static::recordStreamResponses($requestPath, $name);

            return;
        }

        // Sort files numerically
        usort($files, function ($a, $b): int {
            preg_match('/-(\d+)\.sse$/', $a, $matchesA);
            preg_match('/-(\d+)\.sse$/', $b, $matchesB);

            return (int) $matchesA[1] <=> (int) $matchesB[1];
        });

        // Create response sequence from the files
        $responses = array_map(fn ($file) => Http::response(
            file_get_contents($file),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                ...$headers,
            ]
        ), $files);

        if ($responses === []) {
            $responses[] = Http::response(
                "data: {\"error\":\"No recorded stream responses found\"}\n\ndata: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            );
        }

        // Register the fake responses
        Http::fake([
            $requestPath => Http::sequence($responses),
        ])->preventStrayRequests();
    }

    protected static function filePath(string $filePath): string
    {
        return sprintf('%s/%s', __DIR__, $filePath);
    }

    protected static function recordResponses(string $requestPath, string $name): void
    {
        $iterator = 0;

        Http::globalResponseMiddleware(function ($response) use ($name, &$iterator) {
            $iterator++;

            $path = static::filePath("{$name}-{$iterator}.json");

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), recursive: true);
            }

            file_put_contents(
                $path,
                (string) $response->getBody()
            );

            return $response;
        });
    }

    protected static function recordStreamResponses(string $requestPath, string $name): void
    {
        Http::fake(function ($request) use ($requestPath, $name) {
            if (Str::contains($request->url(), $requestPath)) {
                static $iterator = 0;
                $iterator++;

                // Create directory for the response file if needed
                $path = static::filePath("{$name}-{$iterator}.sse");

                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), recursive: true);
                }

                // Get content type or default to application/json
                $contentType = $request->hasHeader('Content-Type')
                    ? $request->header('Content-Type')[0]
                    : 'application/json';

                // Forward the request to the real API with stream option
                $client = new \GuzzleHttp\Client(['stream' => true]);
                $options = [
                    'headers' => $request->headers(),
                    'body' => $request->body(),
                    'stream' => true,
                ];

                $response = $client->request($request->method(), $request->url(), $options);
                $stream = $response->getBody();

                // Open file for writing
                $fileHandle = fopen($path, 'w');

                // Write stream to file in small chunks to avoid memory issues
                while (! $stream->eof()) {
                    $chunk = $stream->read(1024);  // Read 1KB at a time
                    fwrite($fileHandle, $chunk);
                }

                fclose($fileHandle);

                // Return the file contents as the response for the test
                return Http::response(
                    file_get_contents($path),
                    $response->getStatusCode(),
                    [
                        'Content-Type' => 'text/event-stream',
                        'Cache-Control' => 'no-cache',
                        'Connection' => 'keep-alive',
                    ]
                );
            }

            // For non-matching requests, pass through
            return Http::response('{"error":"Not mocked"}', 404);
        });
    }
}
