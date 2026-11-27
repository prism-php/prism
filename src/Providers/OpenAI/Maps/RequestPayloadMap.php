<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Providers\OpenAI\Support\StructuredModeResolver;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Support\RequestPayloadProjection;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\ProviderTool;

/** @internal */
class RequestPayloadMap
{
    /**
     * @param  'text'|'structured'|'stream'  $mode
     * @return array<string, mixed>
     */
    public static function project(TextRequest|StructuredRequest $request, string $mode, bool $chatCompletions = false): array
    {
        // The legacy Chat Completions structured sender does not resolve tools.
        if (! ($chatCompletions && $mode === 'structured')) {
            RequestPayloadProjection::assertProjectable($request);
        }

        if ($mode === 'structured' && $request instanceof StructuredRequest) {
            $request = clone $request;

            if ($chatCompletions) {
                self::appendJsonInstruction($request);

                return self::chatStructured($request);
            }

            if (self::structuredMode($request) === StructuredMode::Json) {
                self::appendJsonInstruction($request);

                return self::structured($request, ['type' => 'json_object']);
            }

            return self::structured($request, self::responseFormat($request));
        }

        if ($request instanceof TextRequest) {
            return match ($mode) {
                'text' => $chatCompletions ? self::chatText($request) : self::text($request),
                'stream' => $chatCompletions ? self::chatStream($request) : self::stream($request),
                default => throw new InvalidArgumentException('Mode must match the request type'),
            };
        }

        throw new InvalidArgumentException('Mode must match the request type');
    }

    public static function structuredMode(StructuredRequest $request): StructuredMode
    {
        return $request->mode() === StructuredMode::Auto
            ? StructuredModeResolver::forModel($request->model())
            : $request->mode();
    }

    /**
     * @param  array<int|string, mixed>|null  $tools
     * @return array<string, mixed>
     */
    public static function text(TextRequest $request, ?array $tools = null): array
    {
        return array_merge([
            'model' => $request->model(),
            'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
            'max_output_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'metadata' => $request->providerOptions('metadata'),
            'tools' => ($tools ?? self::buildTools($request)) ?: null,
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls'),
            'previous_response_id' => $request->providerOptions('previous_response_id'),
            'service_tier' => $request->providerOptions('service_tier'),
            'store' => $request->providerOptions('store'),
            'text' => $request->providerOptions('text_verbosity') ? [
                'verbosity' => $request->providerOptions('text_verbosity'),
            ] : null,
            'truncation' => $request->providerOptions('truncation'),
            'reasoning' => $request->providerOptions('reasoning') ?? (
                $request->reasoningEnabled() === false ? ['effort' => 'minimal'] : null
            ),
        ]));
    }

    /** @return array<string, mixed> */
    public static function stream(TextRequest $request): array
    {
        return array_merge(['stream' => true], self::text($request));
    }

    /**
     * The sender has already appended any JSON-mode instruction.
     *
     * @param  array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool}|array{type: 'json_object'}  $responseFormat
     * @param  array<int|string, mixed>|null  $tools
     * @return array<string, mixed>
     */
    public static function structured(StructuredRequest $request, array $responseFormat, ?array $tools = null): array
    {
        return array_merge([
            'model' => $request->model(),
            'input' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
        ], Arr::whereNotNull([
            'max_output_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'metadata' => $request->providerOptions('metadata'),
            'tools' => $tools ?? self::buildTools($request),
            'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            'parallel_tool_calls' => $request->providerOptions('parallel_tool_calls') ?? false,
            'previous_response_id' => $request->providerOptions('previous_response_id'),
            'service_tier' => $request->providerOptions('service_tier'),
            'truncation' => $request->providerOptions('truncation'),
            'reasoning' => $request->providerOptions('reasoning') ?? (
                $request->reasoningEnabled() === false ? ['effort' => 'minimal'] : null
            ),
            'store' => $request->providerOptions('store'),
            'text' => Arr::whereNotNull([
                'format' => $responseFormat,
                'verbosity' => $request->providerOptions('text_verbosity'),
            ]),
        ]));
    }

    /** @return array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool} */
    public static function responseFormat(StructuredRequest $request): array
    {
        /** @var array{type: 'json_schema', name: string, schema: array<mixed>, strict?: bool} $responseFormat */
        $responseFormat = Arr::whereNotNull([
            'type' => 'json_schema',
            'name' => $request->schema()->name(),
            'schema' => $request->schema()->toArray(),
            'strict' => is_null($request->providerOptions('schema.strict'))
                ? null
                : $request->providerOptions('schema.strict'),
        ]);

        return $responseFormat;
    }

    public static function appendJsonInstruction(StructuredRequest $request): StructuredRequest
    {
        return $request->addMessage(new SystemMessage(sprintf(
            "Respond with JSON that matches the following schema: \n %s",
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }

    /** @return array<int|string, mixed> */
    public static function buildTools(TextRequest|StructuredRequest $request): array
    {
        $tools = ToolMap::map($request->tools());

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
    }

    /** @return array<string, mixed> */
    public static function chatText(TextRequest $request): array
    {
        return Arr::whereNotNull([
            'model' => $request->model(),
            'messages' => (new ChatCompletionsMessageMap($request->messages(), $request->systemPrompts()))(),
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ChatCompletionsToolMap::map($request->tools()),
            'tool_choice' => ChatCompletionsToolChoiceMap::map($request->toolChoice()),
            'verbosity' => $request->providerOptions('text_verbosity'),
        ]);
    }

    /** @return array<string, mixed> */
    public static function chatStructured(StructuredRequest $request): array
    {
        return array_merge([
            'model' => $request->model(),
            'messages' => (new ChatCompletionsMessageMap($request->messages(), $request->systemPrompts()))(),
            'max_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'response_format' => ['type' => 'json_object'],
            'verbosity' => $request->providerOptions('text_verbosity'),
        ]));
    }

    /** @return array<string, mixed> */
    public static function chatStream(TextRequest $request): array
    {
        return array_merge([
            'stream' => true,
            'model' => $request->model(),
            'messages' => (new ChatCompletionsMessageMap($request->messages(), $request->systemPrompts()))(),
            'max_tokens' => $request->maxTokens(),
        ], Arr::whereNotNull([
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => ChatCompletionsToolMap::map($request->tools()),
            'tool_choice' => ChatCompletionsToolChoiceMap::map($request->toolChoice()),
        ]));
    }
}
