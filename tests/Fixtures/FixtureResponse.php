<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FixtureResponse
{
    public static function fromFile(
        string $filePath,
        int $statusCode = 200,
        $headers = []
    ): PromiseInterface {
        return Http::response(
            file_get_contents(static::filePath($filePath)),
            $statusCode,
            $headers,
        );
    }

    public static function filePath(string $filePath): string
    {
        return sprintf('%s/%s', __DIR__, $filePath);
    }

    public static function recordResponses(string $requestPath, string $name): void
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

    /**
     * Record streaming responses (like OpenAI's Server-Sent Events)
     */
    public static function recordStreamResponses(string $requestPath, string $name): void
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

    /**
     * Fake streaming responses from recorded files
     */
    public static function fakeStreamResponses(string $requestPath, string $name): void
    {
        $basePath = dirname(static::filePath("{$name}-1.sse"));

        // Find all recorded .sse files for this test
        $files = collect(is_dir($basePath) ? scandir($basePath) : [])
            ->filter(fn ($file): int|false => preg_match('/^'.preg_quote(basename($name), '/').'-\d+\.sse$/', $file))
            ->map(fn ($file): string => $basePath.'/'.$file)
            ->values()
            ->toArray();

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

    public static function fakeResponseSequence(string $requestPath, string $name, array $headers = [], int $status = 200): void
    {
        $responses = collect(scandir(dirname(static::filePath($name))))
            ->filter(function (string $file) use ($name): int|false {
                $pathInfo = pathinfo($name);
                $filename = $pathInfo['filename'];

                return preg_match('/^'.preg_quote($filename, '/').'-\d+/', $file);
            })
            ->map(fn ($filename): string => dirname(static::filePath($name)).'/'.$filename)
            ->map(fn ($filePath) => Http::response(
                file_get_contents($filePath),
                $status,
                $headers
            ));

        Http::fake([
            $requestPath => Http::sequence($responses->toArray()),
        ])->preventStrayRequests();
    }
}
