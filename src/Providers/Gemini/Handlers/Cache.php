<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Gemini\Maps\MessageMap;
use Prism\Prism\Providers\Gemini\ValueObjects\GeminiCachedObject;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class Cache
{
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

        /** @var Response $response */
        $response = $this->client->post('/cachedContents', $request);

        return $response->json();
    }
}
