<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

describe('Anthropic user message mapping', function (): void {

    it('maps user messages', function (): void {
        expect(MessageMap::map([
            new UserMessage('Who are you?'),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Who are you?'],
            ],
        ]]);
    });

    it('maps user messages with images from path', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Who are you?', [
                Image::fromLocalPath('tests/Fixtures/diamond.png'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('image');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('base64');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('image/png');
    });

    it('maps user messages with images from base64', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Who are you?', [
                Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/diamond.png')), 'image/png'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('image');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('base64');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/diamond.png')));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('image/png');
    });

    it('maps user messages with images from url', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Image::fromUrl('https://prismphp.com/storage/diamond.png'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('image');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('url');
        expect(data_get($mappedMessage, '0.content.1.source.url'))
            ->toBe('https://prismphp.com/storage/diamond.png');
    });

    it('maps user messages with PDF documents from url', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromUrl('https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('url');
        expect(data_get($mappedMessage, '0.content.1.source.url'))
            ->toBe('https://storage.echolabs.dev/api/v1/buckets/public/objects/download?preview=true&prefix=prism-text-generation.pdf');
    });

    it('maps user messages with PDF documents from path', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromLocalPath('tests/Fixtures/test-pdf.pdf'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('base64');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('application/pdf');
    });

    it('maps user messages with PDF documents from base64', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')), 'application/pdf'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('base64');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('application/pdf');
    });

    it('maps user messages with txt documents from path', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromLocalPath('tests/Fixtures/test-text.txt'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('text');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(file_get_contents('tests/Fixtures/test-text.txt'));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('text/plain');
    });

    it('maps user messages with md documents from path', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromLocalPath('tests/Fixtures/test-text.md'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('text');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(file_get_contents('tests/Fixtures/test-text.md'));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('text/plain');
    });

    it('sends every text document as text/plain, which is the only text media type Anthropic accepts', function (string $mimeType): void {
        // prism#49. A storage disk infers text/markdown, text/csv and so on from
        // the extension, and Anthropic refused the whole request: "source.text.
        // media_type: Input should be 'text/plain'". The content is sent as given.
        $mappedMessage = MessageMap::map([
            new UserMessage('Summarise the attached file.', [
                Document::fromRawContent("# Brief\n\n- one\n- two\n", $mimeType, 'brief'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content.1.source'))->toBe([
            'type' => 'text',
            'media_type' => 'text/plain',
            'data' => "# Brief\n\n- one\n- two\n",
        ]);
    })->with([
        'markdown' => ['text/markdown'],
        'csv' => ['text/csv'],
        'html' => ['text/html'],
        'plain with a charset' => ['text/plain; charset=utf-8'],
    ]);

    it('maps user messages with txt documents from text string', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromText('Hello world!'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content'))->toHaveCount(2);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('text');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain('Hello world!');
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('text/plain');
    });
});

describe('Anthropic assistant message mapping', function (): void {
    it('maps assistant message', function (): void {
        expect(MessageMap::map([
            new AssistantMessage('I am Nyx'),
        ]))->toContain([
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'I am Nyx',
                ],
            ],
        ]);
    });

    it('maps assistant message with tool calls', function (): void {
        expect(MessageMap::map([
            new AssistantMessage('I am Nyx', [
                new ToolCall(
                    'tool_1234',
                    'search',
                    [
                        'query' => 'Laravel collection methods',
                    ]
                ),
            ]),
        ]))->toEqual([
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'I am Nyx',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'tool_1234',
                        'name' => 'search',
                        'input' => (object) [
                            'query' => 'Laravel collection methods',
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('maps assistant message with thinking blocks', function (): void {
        expect(MessageMap::map([
            new AssistantMessage(
                content: 'I am Nyx',
                additionalContent: [
                    'thinking' => 'I thought long and hard about who I am deep down.',
                    'thinking_signature' => 'Signed, Nyx',
                ]
            ),
        ]))->toEqual([
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'thinking',
                        'thinking' => 'I thought long and hard about who I am deep down.',
                        'signature' => 'Signed, Nyx',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'I am Nyx',
                    ],
                ],
            ],
        ]);
    });
});

it('maps tool result messages', function (): void {
    expect(MessageMap::map([
        new ToolResultMessage([
            new ToolResult(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ],
                '[search results]'
            ),
        ]),
    ]))->toBe([
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'tool_1234',
                    'content' => '[search results]',
                ],
            ],
        ],
    ]);
});

it('sets the cache type on ToolResultMessage if cacheType providerOptions is set', function (mixed $cacheType): void {
    expect(MessageMap::map([
        (new ToolResultMessage([
            new ToolResult(
                'tool_1234',
                'weather',
                [
                    'city' => 'Dallas',
                ],
                'It is 72°F and sunny in Dallas'
            ),
        ]))->withProviderOptions(['cacheType' => $cacheType]),
    ]))->toBe([
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'tool_1234',
                    'content' => 'It is 72°F and sunny in Dallas',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ],
    ]);
})->with([
    'ephemeral',
    AnthropicCacheType::Ephemeral,
]);

it('only sets cache_control on the last tool result when multiple results exist', function (): void {
    expect(MessageMap::map([
        (new ToolResultMessage([
            new ToolResult(
                'tool_1',
                'weather',
                ['city' => 'New York'],
                'It is 65°F and cloudy in New York'
            ),
            new ToolResult(
                'tool_2',
                'weather',
                ['city' => 'London'],
                'It is 55°F and rainy in London'
            ),
            new ToolResult(
                'tool_3',
                'weather',
                ['city' => 'Tokyo'],
                'It is 70°F and sunny in Tokyo'
            ),
        ]))->withProviderOptions(['cacheType' => 'ephemeral']),
    ]))->toBe([
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'tool_1',
                    'content' => 'It is 65°F and cloudy in New York',
                ],
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'tool_2',
                    'content' => 'It is 55°F and rainy in London',
                ],
                [
                    'type' => 'tool_result',
                    'tool_use_id' => 'tool_3',
                    'content' => 'It is 70°F and sunny in Tokyo',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ],
    ]);
});

it('maps system messages', function (): void {
    expect(MessageMap::mapSystemMessages([
        new SystemMessage('I am Thanos.'),
        new SystemMessage('But call me Bob.'),
    ]))->toBe([
        [
            'type' => 'text',
            'text' => 'I am Thanos.',
        ],
        [
            'type' => 'text',
            'text' => 'But call me Bob.',
        ],
    ]);
});

describe('Anthropic cache mapping', function (): void {
    it('places an assistant message breakpoint only on its final content block', function (AssistantMessage $message, array $types, bool $marked): void {
        if ($marked) {
            $message = $message->copyWithProviderOptions(['cacheType' => AnthropicCacheType::Ephemeral, 'cacheTtl' => '1h']);
        }

        $content = MessageMap::map([$message])[0]['content'];
        expect(array_column($content, 'type'))->toBe($types);
        $count = 0;
        array_walk_recursive($content, function (mixed $value, string|int $key) use (&$count): void {
            if ($key === 'type' && $value === 'ephemeral') {
                $count++;
            }
        });
        expect($count)->toBe($marked ? 1 : 0);
        expect(array_keys(array_filter($content, fn (array $block): bool => array_key_exists('cache_control', $block))))
            ->toBe($marked ? [array_key_last($content)] : []);

        if ($marked) {
            expect($content[array_key_last($content)]['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '1h']);
        }
    })->with([
        'citations' => [new AssistantMessage('', additionalContent: [
            'citations' => [new MessagePartWithCitations('First'), new MessagePartWithCitations('Last')],
        ]), ['text', 'text']],
        'thinking and text' => [new AssistantMessage('Reply', additionalContent: [
            'thinking' => 'Thinking', 'thinking_signature' => 'signature',
        ]), ['thinking', 'text']],
        'text and tools' => [new AssistantMessage('Reply', [new ToolCall('call_1', 'first', []), new ToolCall('call_2', 'last', [])]), ['text', 'tool_use', 'tool_use']],
        'tools only' => [new AssistantMessage('', [new ToolCall('call_1', 'first', [])]), ['tool_use']],
        'thinking and text ending in a tool call' => [new AssistantMessage('Reply', [new ToolCall('call_1', 'search', [])], additionalContent: [
            'thinking' => 'Thinking', 'thinking_signature' => 'signature',
        ]), ['thinking', 'text', 'tool_use']],
        'provider tools and results' => [new AssistantMessage('Reply', additionalContent: [
            'provider_tool_calls' => [['id' => 'call_1', 'name' => 'web_search', 'input' => '{}']],
            'provider_tool_results' => [['type' => 'web_search_tool_result', 'tool_use_id' => 'call_1', 'content' => []]],
        ]), ['text', 'server_tool_use', 'web_search_tool_result']],
    ])->with([true, false]);

    it('does not add a cache block to an empty or thinking-only assistant message', function (array $additionalContent, array $types): void {
        $message = (new AssistantMessage('', additionalContent: $additionalContent))
            ->withProviderOptions(['cacheType' => 'ephemeral']);
        $content = MessageMap::map([$message])[0]['content'];

        expect(array_column($content, 'type'))->toBe($types);
        expect(array_filter($content, fn (array $block): bool => array_key_exists('cache_control', $block)))->toBe([]);
    })->with([
        'empty' => [[], []],
        'thinking only' => [['thinking' => 'Thinking', 'thinking_signature' => 'signature'], ['thinking']],
    ]);

    it('places a user message breakpoint only on its final content block', function (bool $marked, bool $attachments): void {
        $message = new UserMessage('Review these attachments.', $attachments ? [
            Document::fromText('First document'),
            Image::fromUrl('https://example.test/first.png'),
            Document::fromText('Last document'),
            Image::fromUrl('https://example.test/last.png'),
        ] : []);

        if ($marked) {
            $message->withProviderOptions(['cacheType' => 'ephemeral']);
        }

        $content = MessageMap::map([$message])[0]['content'];
        $count = 0;
        array_walk_recursive($content, function (mixed $value, string|int $key) use (&$count): void {
            if ($key === 'type' && $value === 'ephemeral') {
                $count++;
            }
        });

        expect(array_column($content, 'type'))->toBe($attachments
            ? ['text', 'image', 'image', 'document', 'document']
            : ['text']);
        expect($count)->toBe($marked ? 1 : 0);
        expect(array_keys(array_filter($content, fn (array $block): bool => array_key_exists('cache_control', $block))))
            ->toBe($marked ? [array_key_last($content)] : []);
    })->with([
        'marked with attachments' => [true, true],
        'marked text only' => [true, false],
        'unmarked with attachments' => [false, true],
        'unmarked text only' => [false, false],
    ]);

    it('sets the cache type on a UserMessage if cacheType providerOptions is set on message', function (mixed $cacheType): void {
        expect(MessageMap::map([
            (new UserMessage(content: 'Who are you?'))->withProviderOptions(['cacheType' => $cacheType]),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ]]);
    })->with([
        'ephemeral',
        AnthropicCacheType::Ephemeral,
    ]);

    it('sets the cache type on a UserMessage image if cacheType providerOptions is set on message', function (): void {
        expect(MessageMap::map([
            (new UserMessage(
                content: 'Who are you?',
                additionalContent: [Image::fromLocalPath('tests/Fixtures/diamond.png')]
            ))->withProviderOptions(['cacheType' => 'ephemeral']),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                ],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/png',
                        'data' => base64_encode(file_get_contents('tests/Fixtures/diamond.png')),
                    ],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ]]);
    });

    it('sets the cache type on a UserMessage document if cacheType providerOptions is set on message', function (): void {
        expect(MessageMap::map([
            (new UserMessage(
                content: 'Who are you?',
                additionalContent: [Document::fromLocalPath('tests/Fixtures/test-pdf.pdf')]
            ))->withProviderOptions(['cacheType' => 'ephemeral']),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                ],
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => base64_encode(file_get_contents('tests/Fixtures/test-pdf.pdf')),
                    ],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ]]);
    });

    it('sets the cache type on an AssistantMessage if cacheType providerOptions is set on message', function (mixed $cacheType): void {
        expect(MessageMap::map([
            (new AssistantMessage(content: 'Who are you?'))->withProviderOptions(['cacheType' => $cacheType]),
        ]))->toBe([[
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                    'cache_control' => ['type' => AnthropicCacheType::Ephemeral->value],
                ],
            ],
        ]]);
    })->with([
        'ephemeral',
        AnthropicCacheType::Ephemeral,
    ]);

    it('sets the cache type on a SystemMessage if cacheType providerOptions is set on message', function (mixed $cacheType): void {
        expect(MessageMap::mapSystemMessages([
            (new SystemMessage(content: 'Who are you?'))->withProviderOptions(['cacheType' => $cacheType]),
        ]))->toBe([
            [
                'type' => 'text',
                'text' => 'Who are you?',
                'cache_control' => ['type' => AnthropicCacheType::Ephemeral->value],
            ],
        ]);
    })->with([
        'ephemeral',
        AnthropicCacheType::Ephemeral,
    ]);

    it('sets the cache ttl on a UserMessage if cacheTtl providerOptions is set on message', function (): void {
        expect(MessageMap::map([
            (new UserMessage(content: 'Who are you?'))->withProviderOptions([
                'cacheType' => 'ephemeral',
                'cacheTtl' => '1h',
            ]),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                    'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
                ],
            ],
        ]]);
    });

    it('sets the cache ttl on an AssistantMessage if cacheTtl providerOptions is set on message', function (): void {
        expect(MessageMap::map([
            (new AssistantMessage(content: 'Who are you?'))->withProviderOptions([
                'cacheType' => 'ephemeral',
                'cacheTtl' => '5m',
            ]),
        ]))->toBe([[
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                    'cache_control' => ['type' => AnthropicCacheType::Ephemeral->value, 'ttl' => '5m'],
                ],
            ],
        ]]);
    });

    it('sets the cache ttl on a SystemMessage if cacheTtl providerOptions is set on message', function (): void {
        expect(MessageMap::mapSystemMessages([
            (new SystemMessage(content: 'Who are you?'))->withProviderOptions([
                'cacheType' => 'ephemeral',
                'cacheTtl' => '1h',
            ]),
        ]))->toBe([
            [
                'type' => 'text',
                'text' => 'Who are you?',
                'cache_control' => ['type' => AnthropicCacheType::Ephemeral->value, 'ttl' => '1h'],
            ],
        ]);
    });

    it('sets the cache ttl on a ToolResultMessage if cacheTtl providerOptions is set on message', function (): void {
        expect(MessageMap::map([
            (new ToolResultMessage([
                new ToolResult(
                    'tool_1234',
                    'weather',
                    ['city' => 'Dallas'],
                    'It is 72°F and sunny in Dallas'
                ),
            ]))->withProviderOptions([
                'cacheType' => 'ephemeral',
                'cacheTtl' => '5m',
            ]),
        ]))->toBe([
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => 'tool_1234',
                        'content' => 'It is 72°F and sunny in Dallas',
                        'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m'],
                    ],
                ],
            ],
        ]);
    });
});

describe('Anthropic citations mapping', function (): void {
    it('citations back to Anthropic format', function (): void {
        $citation = new Citation(
            sourceType: CitationSourceType::Document,
            source: 0,
            sourceText: 'Sample citation text',
            sourceTitle: 'Test Document',
            sourcePositionType: CitationSourcePositionType::Character,
            sourceStartIndex: 10,
            sourceEndIndex: 30
        );

        $messagePartWithCitations = new MessagePartWithCitations(
            outputText: 'Here is some text with citations.',
            citations: [$citation]
        );

        $assistantMessage = new AssistantMessage(
            content: '',
            additionalContent: [
                'citations' => [$messagePartWithCitations],
            ]
        );

        $mapped = MessageMap::map([$assistantMessage]);

        expect($mapped[0]['content'][0])->toHaveKey('type', 'text');
        expect($mapped[0]['content'][0])->toHaveKey('text', 'Here is some text with citations.');
        expect($mapped[0]['content'][0])->toHaveKey('citations');
        expect($mapped[0]['content'][0]['citations'])->toHaveCount(1);
        expect($mapped[0]['content'][0]['citations'][0])->toHaveKey('type', 'char_location');
        expect($mapped[0]['content'][0]['citations'][0])->toHaveKey('cited_text', 'Sample citation text');
        expect($mapped[0]['content'][0]['citations'][0])->toHaveKey('document_index', 0);
        expect($mapped[0]['content'][0]['citations'][0])->toHaveKey('document_title', 'Test Document');
        expect($mapped[0]['content'][0]['citations'][0])->toHaveKey('start_char_index', 10);
        expect($mapped[0]['content'][0]['citations'][0])->toHaveKey('end_char_index', 30);
    });
});

describe('Anthropic provider tool calls mapping', function (): void {
    it('maps assistant message with provider tool calls', function (): void {
        expect(MessageMap::map([
            new AssistantMessage(
                content: 'I have used a provider tool.',
                additionalContent: [
                    'provider_tool_calls' => [
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_xyz789',
                            'name' => 'web_search',
                            'input' => '{"query":"london weather"}',
                        ],
                    ],
                ]
            ),
        ]))->toBe([
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'I have used a provider tool.',
                    ],
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_xyz789',
                        'name' => 'web_search',
                        'input' => [
                            'query' => 'london weather',
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('maps assistant message with web search tool results', function (): void {
        expect(MessageMap::map([
            new AssistantMessage(
                content: 'Here are the web search results.',
                additionalContent: [
                    'provider_tool_results' => [
                        [
                            'type' => 'web_search_tool_result',
                            'tool_use_id' => 'srvtoolu_xyz789',
                            'content' => [
                                'results' => [
                                    ['type' => 'web_search_result', 'title' => 'London Weather Today', 'url' => 'https://weather.com/london'],
                                ],
                            ],
                        ],
                    ],
                ]
            ),
        ]))->toBe([
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Here are the web search results.',
                    ],
                    [
                        'type' => 'web_search_tool_result',
                        'tool_use_id' => 'srvtoolu_xyz789',
                        'content' => [
                            'results' => [
                                ['type' => 'web_search_result', 'title' => 'London Weather Today', 'url' => 'https://weather.com/london'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    });
});
