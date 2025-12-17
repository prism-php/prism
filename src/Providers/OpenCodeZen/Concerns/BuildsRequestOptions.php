<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenCodeZen\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenCodeZen\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenCodeZen\Maps\ToolMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

trait BuildsRequestOptions
{
    /**
     * Assemble optional OpenCodeZen request parameters that Prism exposes via provider options.
     *
     * @param  array<string, mixed>  $additional
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(TextRequest|StructuredRequest $request, array $additional = []): array
    {
        // Keep OpenCodeZen option surface flexible; OpenAI-compatible parameters
        $options = $request->providerOptions() ?? [];

        $options = array_merge($options, Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'max_tokens' => $request->maxTokens(),
        ]));

        if ($request instanceof TextRequest) {
            $options = array_merge($options, Arr::whereNotNull([
                'tools' => ToolMap::map($request->tools()),
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ]));
        }

        return Arr::whereNotNull(array_merge($options, $additional));
    }
}
