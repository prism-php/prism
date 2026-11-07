<?php

declare(strict_types=1);

namespace Tests\Fixtures\ToolFailure;

use GuzzleHttp\Psr7\Response as WireResponse;
use Illuminate\Http\Client\Response;
use Prism\Prism\Providers\OpenAI\Handlers\Text;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\ResponseBuilder;

class Provider extends Text
{
    public int $requests = 0;

    public function __construct()
    {
        $this->responseBuilder = new ResponseBuilder;
    }

    public function recordedSteps(): int
    {
        return $this->responseBuilder->steps->count();
    }

    protected function sendRequest(Request $request): Response
    {
        $this->requests++;

        return new Response(new WireResponse(200, ['Content-Type' => 'application/json'], file_get_contents(__DIR__.'/../openai/generate-text-with-multiple-tools-1.json')));
    }
}
