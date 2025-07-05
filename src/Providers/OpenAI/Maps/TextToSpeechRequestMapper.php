<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Illuminate\Support\Arr;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Contracts\ProviderRequestMapper;
use Prism\Prism\Enums\Provider;

class TextToSpeechRequestMapper extends ProviderRequestMapper
{
    public function __construct(
        public readonly TextToSpeechRequest $request
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $providerOptions = $this->request->providerOptions();

        $baseData = [
            'model' => $this->request->model(),
            'input' => $this->request->input(),
            'voice' => $this->request->voice(),
        ];

        $supportedOptions = [
            'response_format' => $providerOptions['response_format'] ?? null,
            'speed' => $providerOptions['speed'] ?? null,
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
