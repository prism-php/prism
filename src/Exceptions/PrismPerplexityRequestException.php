<?php

declare(strict_types=1);

namespace Prism\Prism\Exceptions;

use Prism\Prism\Providers\Perplexity\ResponseDiagnostics;

/** HTTP failure; provider body is opt-in, never a public serializable field. */
class PrismPerplexityRequestException extends PrismException
{
    private readonly ?string $requestId;

    public function __construct(
        int $status,
        #[\SensitiveParameter] private readonly string $providerBody,
        ?string $requestId,
    ) {
        $data = json_decode($providerBody, true);
        $data = is_array($data) ? $data : ['error' => ['message' => $providerBody]];
        $this->requestId = ResponseDiagnostics::requestId($data, $requestId);
        parent::__construct('Perplexity Error ['.$status.']: '.ResponseDiagnostics::message($data, $this->requestId), $status);
        $this->httpStatus = $status;
    }

    public function providerRequestId(): ?string
    {
        return $this->requestId;
    }

    public function providerResponseBody(): string
    {
        return ResponseDiagnostics::bounded($this->providerBody);
    }

    public function providerCode(): string|int|null
    {
        $code = data_get(json_decode($this->providerBody, true), 'error.code');

        return is_string($code) || is_int($code) ? $code : null;
    }
}
