<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Providers\OpenAI\Maps\RequestPayloadMap;
use Prism\Prism\Text\Request;

trait BuildsRequestBody
{
    use BuildsTools;

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestBody(Request $request): array
    {
        return RequestPayloadMap::text($request, $this->buildTools($request));
    }
}
