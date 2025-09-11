<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Processors;

use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Providers\Anthropic\Maps\CitationsMapper;
use Prism\Prism\Providers\Anthropic\ValueObjects\StreamState;
use Prism\Prism\Text\Chunk;
use Prism\Prism\ValueObjects\ToolCall;

class ContentBlockProcessor
{
    /**
     * @param  array<string, mixed>  $chunk
     */
    public function processBlockStart(array $chunk, StreamState $state): null
    {
        $blockType = data_get($chunk, 'content_block.type');
        $blockIndex = (int) data_get($chunk, 'index');

        $state
            ->setTempContentBlockType($blockType)
            ->setTempContentBlockIndex($blockIndex);

        if ($blockType === 'tool_use') {
            $state->addToolCall($blockIndex, [
                'id' => data_get($chunk, 'content_block.id'),
                'name' => data_get($chunk, 'content_block.name'),
                'input' => '',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    public function processBlockDelta(array $chunk, StreamState $state): ?Chunk
    {
        $deltaType = data_get($chunk, 'delta.type');
        $blockType = $state->tempContentBlockType();

        if ($blockType === 'text') {
            return $this->handleTextBlockDelta($chunk, $deltaType, $state);
        }

        if ($blockType === 'tool_use' && $deltaType === 'input_json_delta') {
            return $this->handleToolInputDelta($chunk, $state);
        }

        if ($blockType === 'thinking') {
            return $this->handleThinkingBlockDelta($chunk, $deltaType, $state);
        }

        return null;
    }

    public function processBlockStop(StreamState $state): ?Chunk
    {
        $blockType = $state->tempContentBlockType();
        $blockIndex = $state->tempContentBlockIndex();

        $chunk = null;

        if ($blockType === 'tool_use' && $blockIndex !== null && isset($state->toolCalls()[$blockIndex])) {
            $toolCallData = $state->toolCalls()[$blockIndex];
            $input = data_get($toolCallData, 'input');

            if (is_string($input) && json_validate($input)) {
                $input = json_decode($input, true);
            }

            $toolCall = new ToolCall(
                id: data_get($toolCallData, 'id'),
                name: data_get($toolCallData, 'name'),
                arguments: $input
            );

            $chunk = new Chunk(
                text: '',
                toolCalls: [$toolCall],
                chunkType: ChunkType::ToolCall
            );
        }

        $state->resetContentBlock();

        return $chunk;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleTextBlockDelta(array $chunk, ?string $deltaType, StreamState $state): ?Chunk
    {
        if ($deltaType === 'text_delta') {
            $textDelta = $this->extractTextDelta($chunk);

            if ($textDelta !== '' && $textDelta !== '0') {
                $state->appendText($textDelta);
                $additionalContent = $this->buildCitationContent($state);

                return new Chunk(
                    text: $textDelta,
                    finishReason: null,
                    additionalContent: $additionalContent,
                    chunkType: ChunkType::Text
                );
            }
        }

        if ($deltaType === 'citations_delta') {
            $state->setTempCitation(data_get($chunk, 'delta.citation', null));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function extractTextDelta(array $chunk): string
    {
        $textDelta = data_get($chunk, 'delta.text', '');

        if (empty($textDelta)) {
            $textDelta = data_get($chunk, 'delta.text_delta.text', '');
        }

        if (empty($textDelta)) {
            return data_get($chunk, 'text', '');
        }

        return $textDelta;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleToolInputDelta(array $chunk, StreamState $state): ?Chunk
    {
        $jsonDelta = data_get($chunk, 'delta.partial_json', '');

        if (empty($jsonDelta)) {
            $jsonDelta = data_get($chunk, 'delta.input_json_delta.partial_json', '');
        }

        $blockIndex = $state->tempContentBlockIndex();

        if ($blockIndex !== null) {
            $state->appendToolCallInput($blockIndex, $jsonDelta);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleThinkingBlockDelta(array $chunk, ?string $deltaType, StreamState $state): ?Chunk
    {
        if ($deltaType === 'thinking_delta') {
            $thinkingDelta = data_get($chunk, 'delta.thinking', '');

            if (empty($thinkingDelta)) {
                $thinkingDelta = data_get($chunk, 'delta.thinking_delta.thinking', '');
            }

            $state->appendThinking($thinkingDelta);

            return new Chunk(
                text: $thinkingDelta,
                finishReason: null,
                chunkType: ChunkType::Thinking
            );
        }

        if ($deltaType === 'signature_delta') {
            $signatureDelta = data_get($chunk, 'delta.signature', '');

            if (empty($signatureDelta)) {
                $signatureDelta = data_get($chunk, 'delta.signature_delta.signature', '');
            }

            $state->appendThinkingSignature($signatureDelta);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCitationContent(StreamState $state): array
    {
        $additionalContent = [];

        if ($state->tempCitation() !== null) {
            $messagePartWithCitations = CitationsMapper::mapFromAnthropic([
                'type' => 'text',
                'text' => $state->text(),
                'citations' => [$state->tempCitation()],
            ]);

            $state->addCitation($messagePartWithCitations);

            $additionalContent['citationIndex'] = count($state->citations()) - 1;
        }

        return $additionalContent;
    }
}
