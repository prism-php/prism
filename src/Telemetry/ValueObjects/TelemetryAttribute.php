<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

enum TelemetryAttribute: string
{
    // Provider attributes
    case ProviderName = 'prism.provider.name';
    case ProviderModel = 'prism.provider.model';

    // Request attributes
    case RequestType = 'prism.request.type';
    case RequestTokensInput = 'prism.request.tokens.input';
    case RequestTokensOutput = 'prism.request.tokens.output';
    case RequestDuration = 'prism.request.duration_ms';

    // Tool attributes
    case ToolName = 'prism.tool.name';
    case ToolSuccess = 'prism.tool.success';
    case ToolDuration = 'prism.tool.duration_ms';

    // Error attributes
    case ErrorType = 'prism.error.type';
    case ErrorMessage = 'prism.error.message';
    case ErrorCode = 'prism.error.code';

    // Stream attributes
    case StreamChunksTotal = 'prism.stream.chunks.total';
    case StreamTokensPerSecond = 'prism.stream.tokens_per_second';
}
