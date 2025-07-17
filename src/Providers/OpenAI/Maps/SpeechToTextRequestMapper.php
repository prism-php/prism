<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Contracts\ProviderRequestMapper;
use Prism\Prism\Enums\Provider;

class SpeechToTextRequestMapper extends ProviderRequestMapper
{
    public function __construct(
        public readonly SpeechToTextRequest $request
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $audioFile = $this->request->input();
        $providerOptions = $this->request->providerOptions();

        $baseData = [
            'file' => $audioFile->resource(),
            'model' => $this->request->model(),
        ];

        $supportedOptions = [
            'language' => $providerOptions['language'] ?? null,
            'prompt' => $providerOptions['prompt'] ?? null,
            'response_format' => $providerOptions['response_format'] ?? null,
            'temperature' => $providerOptions['temperature'] ?? null,
        ];

        return array_merge(
            $baseData,
            Arr::whereNotNull($supportedOptions),
            array_diff_key($providerOptions, $supportedOptions)
        );
    }

    protected function provider(): string|Provider
    {
        return Provider::OpenAI;
    }
}
