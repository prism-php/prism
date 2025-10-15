<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenRouter\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

trait BuildsRequestOptions
{
    /**
     * Assemble optional OpenRouter request parameters that Prism exposes via provider options.
     *
     * @param  array<string, mixed>  $additional
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(TextRequest|StructuredRequest $request, array $additional = []): array
    {
        $options = [
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'stop' => $request->providerOptions('stop'),
            'seed' => $request->providerOptions('seed'),
            'top_k' => $request->providerOptions('top_k'),
            'frequency_penalty' => $request->providerOptions('frequency_penalty'),
            'presence_penalty' => $request->providerOptions('presence_penalty'),
            'repetition_penalty' => $request->providerOptions('repetition_penalty'),
            'min_p' => $request->providerOptions('min_p'),
            'top_a' => $request->providerOptions('top_a'),
            'logit_bias' => $request->providerOptions('logit_bias'),
            'logprobs' => $request->providerOptions('logprobs'),
            'top_logprobs' => $request->providerOptions('top_logprobs'),
            'prediction' => $request->providerOptions('prediction'),
            'transforms' => $request->providerOptions('transforms'),
            'models' => $request->providerOptions('models'),
            'route' => $request->providerOptions('route'),
            'provider' => $request->providerOptions('provider'),
            'user' => $request->providerOptions('user'),
            'reasoning' => $request->providerOptions('reasoning'),
            'verbosity' => $request->providerOptions('verbosity'),
        ];

        if ($request instanceof TextRequest) {
            $options = array_merge($options, [
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
            ]);
        }

        return Arr::whereNotNull(array_merge($options, $additional));
    }
}
