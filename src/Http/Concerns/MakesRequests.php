<?php

declare(strict_types=1);

namespace Prism\Prism\Http\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use Prism\Prism\Http\Exceptions\ConnectionException;
use Prism\Prism\Http\Response;

trait MakesRequests
{
    protected function executeRequest(string $method, string $url, array $options, array $middleware = []): Response
    {
        $client = $this->buildHttpClient($middleware);

        try {
            $guzzleResponse = $client->request($method, $url, $options);
        } catch (ConnectException $e) {
            throw new ConnectionException($e->getMessage(), 0, $e);
        }

        return new Response($guzzleResponse);
    }

    protected function buildHttpClient(array $middleware = []): Client
    {
        $handler = HandlerStack::create();

        foreach ($middleware as $middlewareCallable) {
            $handler->push($middlewareCallable);
        }

        return new Client(['handler' => $handler]);
    }
}
