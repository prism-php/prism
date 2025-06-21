<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Prism\Prism\Concerns\HandlesRequestExceptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiCachedObject;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Throwable;

class Cache
{
    use HandlesRequestExceptions;

    /**
     * @param  Message[]  $messages
     * @param  SystemMessage[]  $systemPrompts
     * @param  int  $ttl  Measured in seconds
     */
    public function __construct(
        protected PendingRequest $client,
        protected string $model,
        protected array $messages,
        protected array $systemPrompts,
        protected ?int $ttl = null
    ) {}

    public function handle(): GeminiCachedObject
    {
        return GeminiCachedObject::fromResponse($this->model, $this->sendRequest());
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(): array
    {
        $request = Arr::whereNotNull([
            'model' => 'models/'.$this->model,
            ...(new MessageMap($this->messages, $this->systemPrompts))(),
            'ttl' => $this->ttl.'s',
        ]);

        try {
            $response = $this->client->post('/cachedContents', $request);
        } catch (Throwable $e) {
            $this->handleRequestExceptions($this->model, $e);
        }

        return $response->json();
    }
}
