<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as Psr7Response;
use Prism\Prism\Http\Exceptions\RequestException;
use Prism\Prism\Http\Response;

it('can get status code', function (): void {
    $psr7Response = new Psr7Response(200);
    $response = new Response($psr7Response);

    expect($response->status())->toBe(200);
});

it('can get reason phrase', function (): void {
    $psr7Response = new Psr7Response(200, [], '', '1.1', 'OK');
    $response = new Response($psr7Response);

    expect($response->reason())->toBe('OK');
});

it('can get body', function (): void {
    $psr7Response = new Psr7Response(200, [], 'test body');
    $response = new Response($psr7Response);

    expect($response->body())->toBe('test body');
});

it('can parse json', function (): void {
    $psr7Response = new Psr7Response(200, [], '{"message": "success"}');
    $response = new Response($psr7Response);

    expect($response->json())->toBe(['message' => 'success']);
    expect($response->json('message'))->toBe('success');
});

it('handles invalid json gracefully', function (): void {
    $psr7Response = new Psr7Response(200, [], 'invalid json');
    $response = new Response($psr7Response);

    expect($response->json())->toBeNull();
    expect($response->json('message', 'default'))->toBe('default');
});

it('can get object', function (): void {
    $psr7Response = new Psr7Response(200, [], '{"message": "success"}');
    $response = new Response($psr7Response);

    $object = $response->object();

    expect($object)->toBeObject();
    expect($object->message)->toBe('success');
});

it('can get collection', function (): void {
    $psr7Response = new Psr7Response(200, [], '{"items": [1, 2, 3]}');
    $response = new Response($psr7Response);

    $collection = $response->collect('items');

    expect($collection)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($collection->toArray())->toBe([1, 2, 3]);
});

it('knows if successful', function (): void {
    $successResponse = new Response(new Psr7Response(200));
    $failedResponse = new Response(new Psr7Response(404));

    expect($successResponse->successful())->toBeTrue();
    expect($failedResponse->successful())->toBeFalse();
});

it('knows if failed', function (): void {
    $successResponse = new Response(new Psr7Response(200));
    $clientErrorResponse = new Response(new Psr7Response(404));
    $serverErrorResponse = new Response(new Psr7Response(500));

    expect($successResponse->failed())->toBeFalse();
    expect($clientErrorResponse->failed())->toBeTrue();
    expect($serverErrorResponse->failed())->toBeTrue();
});

it('knows if client error', function (): void {
    $successResponse = new Response(new Psr7Response(200));
    $clientErrorResponse = new Response(new Psr7Response(404));
    $serverErrorResponse = new Response(new Psr7Response(500));

    expect($successResponse->clientError())->toBeFalse();
    expect($clientErrorResponse->clientError())->toBeTrue();
    expect($serverErrorResponse->clientError())->toBeFalse();
});

it('knows if server error', function (): void {
    $successResponse = new Response(new Psr7Response(200));
    $clientErrorResponse = new Response(new Psr7Response(404));
    $serverErrorResponse = new Response(new Psr7Response(500));

    expect($successResponse->serverError())->toBeFalse();
    expect($clientErrorResponse->serverError())->toBeFalse();
    expect($serverErrorResponse->serverError())->toBeTrue();
});

it('can check specific status codes', function (): void {
    expect((new Response(new Psr7Response(200)))->ok())->toBeTrue();
    expect((new Response(new Psr7Response(201)))->created())->toBeTrue();
    expect((new Response(new Psr7Response(202)))->accepted())->toBeTrue();
    expect((new Response(new Psr7Response(204)))->noContent())->toBeTrue();
    expect((new Response(new Psr7Response(401)))->unauthorized())->toBeTrue();
    expect((new Response(new Psr7Response(403)))->forbidden())->toBeTrue();
    expect((new Response(new Psr7Response(404)))->notFound())->toBeTrue();
    expect((new Response(new Psr7Response(422)))->unprocessableEntity())->toBeTrue();
    expect((new Response(new Psr7Response(429)))->tooManyRequests())->toBeTrue();
});

it('can get headers', function (): void {
    $psr7Response = new Psr7Response(200, ['Content-Type' => 'application/json']);
    $response = new Response($psr7Response);
    expect($response->hasHeader('Content-Type'))->toBeTrue();
    expect($response->header('Content-Type'))->toBe('application/json');
    expect($response->headers())->toHaveKey('Content-Type');

});

it('can throw on failure', function (): void {
    $response = new Response(new Psr7Response(404));

    expect(fn (): \Prism\Prism\Http\Response => $response->throw())->toThrow(RequestException::class);
});

it('does not throw on success', function (): void {
    $response = new Response(new Psr7Response(200));

    expect($response->throw())->toBe($response);
});

it('can throw conditionally', function (): void {
    $response = new Response(new Psr7Response(404));

    expect(fn (): \Prism\Prism\Http\Response => $response->throwIf(true))->toThrow(RequestException::class);
    expect($response->throwIf(false))->toBe($response);
});

it('can throw on status code', function (): void {
    $response = new Response(new Psr7Response(404));

    expect(fn (): \Prism\Prism\Http\Response => $response->throwIfStatus(404))->toThrow(RequestException::class);
    expect($response->throwIfStatus(200))->toBe($response);
});

it('can convert to string', function (): void {
    $psr7Response = new Psr7Response(200, [], 'response body');
    $response = new Response($psr7Response);

    expect((string) $response)->toBe('response body');
});
