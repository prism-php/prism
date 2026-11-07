<?php

declare(strict_types=1);

namespace Prism\Prism\Contracts;

use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

interface ProjectsRequestPayload
{
    /**
     * Project the next JSON request body without sending it or changing the request.
     * Null means this provider does not expose a projection. No credentials or
     * transport options are included. Media and tools can incur costs beyond JSON.
     * Pending tool approval resolution throws PrismUnprojectableRequestException;
     * projecting never executes a tool or evaluates an approval callback.
     *
     * @param  'text'|'structured'|'stream'  $mode
     * @return array<string, mixed>|null
     */
    public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array;
}
