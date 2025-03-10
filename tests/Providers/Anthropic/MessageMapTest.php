<?php

declare(strict_types=1);

namespace Tests\Providers\Anthropic;

use InvalidArgumentException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap;
use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\Support\Document;
use Prism\Prism\ValueObjects\Messages\Support\Image;
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
                Image::fromPath('tests/Fixtures/test-image.png'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('image');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('base64');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('image/png');
    });

    it('maps user messages with images from base64', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Who are you?', [
                Image::fromBase64(base64_encode(file_get_contents('tests/Fixtures/test-image.png')), 'image/png'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('image');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('base64');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(base64_encode(file_get_contents('tests/Fixtures/test-image.png')));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('image/png');
    });

    it('does not maps user messages with images from url', function (): void {
        $this->expectException(InvalidArgumentException::class);
        MessageMap::map([
            new UserMessage('Who are you?', [
                Image::fromUrl('https://storage.echolabs.dev/assets/logo.png'),
            ]),
        ]);
    });

    it('maps user messages with PDF documents from path', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromPath('tests/Fixtures/test-pdf.pdf'),
            ]),
        ]);

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
                Document::fromPath('tests/Fixtures/test-text.txt'),
            ]),
        ]);

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
                Document::fromPath('tests/Fixtures/test-text.md'),
            ]),
        ]);

        expect(data_get($mappedMessage, '0.content.1.type'))
            ->toBe('document');
        expect(data_get($mappedMessage, '0.content.1.source.type'))
            ->toBe('text');
        expect(data_get($mappedMessage, '0.content.1.source.data'))
            ->toContain(file_get_contents('tests/Fixtures/test-text.md'));
        expect(data_get($mappedMessage, '0.content.1.source.media_type'))
            ->toBe('text/plain');
    });

    it('maps user messages with txt documents from text string', function (): void {
        $mappedMessage = MessageMap::map([
            new UserMessage('Here is the document', [
                Document::fromText('Hello world!'),
            ]),
        ]);

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
        ]))->toBe([
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
                        'input' => [
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
    it('sets the cache type on a UserMessage if cacheType providerMeta is set on message', function (mixed $cacheType): void {
        expect(MessageMap::map([
            (new UserMessage(content: 'Who are you?'))->withProviderMeta(Provider::Anthropic, ['cacheType' => $cacheType]),
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

    it('sets the cache type on a UserMessage image if cacheType providerMeta is set on message', function (): void {
        expect(MessageMap::map([
            (new UserMessage(
                content: 'Who are you?',
                additionalContent: [Image::fromPath('tests/Fixtures/test-image.png')]
            ))->withProviderMeta(Provider::Anthropic, ['cacheType' => 'ephemeral']),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/png',
                        'data' => base64_encode(file_get_contents('tests/Fixtures/test-image.png')),
                    ],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
        ]]);
    });

    it('sets the cache type on a UserMessage document if cacheType providerMeta is set on message', function (): void {
        expect(MessageMap::map([
            (new UserMessage(
                content: 'Who are you?',
                additionalContent: [Document::fromPath('tests/Fixtures/test-pdf.pdf')]
            ))->withProviderMeta(Provider::Anthropic, ['cacheType' => 'ephemeral']),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Who are you?',
                    'cache_control' => ['type' => 'ephemeral'],
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

    it('sets the cache type on an AssistantMessage if cacheType providerMeta is set on message', function (mixed $cacheType): void {
        expect(MessageMap::map([
            (new AssistantMessage(content: 'Who are you?'))->withProviderMeta(Provider::Anthropic, ['cacheType' => $cacheType]),
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

    it('sets the cache type on a SystemMessage if cacheType providerMeta is set on message', function (mixed $cacheType): void {
        expect(MessageMap::mapSystemMessages([
            (new SystemMessage(content: 'Who are you?'))->withProviderMeta(Provider::Anthropic, ['cacheType' => $cacheType]),
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
});

describe('Anthropic citation mapping', function (): void {
    it('sets citiations to true on a UserMessage Document if citations providerMeta is true', function (): void {
        expect(MessageMap::map([
            (new UserMessage(
                content: 'What color is the grass and sky?',
                additionalContent: [
                    Document::fromText('The grass is green. The sky is blue.')->withProviderMeta(Provider::Anthropic, ['citations' => true]),
                ]
            )),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'What color is the grass and sky?',
                ],
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'text',
                        'media_type' => 'text/plain',
                        'data' => 'The grass is green. The sky is blue.',
                    ],
                    'citations' => ['enabled' => true],
                ],
            ],
        ]]);
    });

    test('MessageMap applies citations to all documents if requestProviderMeta has citations true', function (): void {
        $messages = [
            (new UserMessage(
                content: 'What color is the grass and sky?',
                additionalContent: [
                    Document::fromText('The grass is green.', 'All aboout the grass.', 'A novel look into the colour of grass.'),
                    Document::fromText('The sky is blue.'),
                ]
            )),
            (new UserMessage(
                content: 'What color is sea and earth?',
                additionalContent: [
                    Document::fromText('The sea is blue'),
                    Document::fromText('The earth is brown.'),
                ]
            )),
        ];

        expect(MessageMap::map($messages, ['citations' => true]))->toBe([
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'What color is the grass and sky?',
                    ],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'text',
                            'media_type' => 'text/plain',
                            'data' => 'The grass is green.',
                        ],
                        'title' => 'All aboout the grass.',
                        'context' => 'A novel look into the colour of grass.',
                        'citations' => ['enabled' => true],
                    ],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'text',
                            'media_type' => 'text/plain',
                            'data' => 'The sky is blue.',
                        ],
                        'citations' => ['enabled' => true],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'What color is sea and earth?',
                    ],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'text',
                            'media_type' => 'text/plain',
                            'data' => 'The sea is blue',
                        ],
                        'citations' => ['enabled' => true],
                    ],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'text',
                            'media_type' => 'text/plain',
                            'data' => 'The earth is brown.',
                        ],
                        'citations' => ['enabled' => true],
                    ],
                ],
            ],
        ]);
    });

    it('maps a chunked document correctly', function (): void {
        expect(MessageMap::map([
            (new UserMessage(
                content: 'What color is the grass and sky?',
                additionalContent: [
                    Document::fromChunks(['chunk1', 'chunk2'])->withProviderMeta(Provider::Anthropic, ['citations' => true]),
                ]
            )),
        ]))->toBe([[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'What color is the grass and sky?',
                ],
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'content',
                        'content' => [
                            ['type' => 'text', 'text' => 'chunk1'],
                            ['type' => 'text', 'text' => 'chunk2'],
                        ],
                    ],
                    'citations' => ['enabled' => true],
                ],
            ],
        ]]);
    });

    it('maps an assistant message with PDF citations back to its original format', function (): void {
        $block_one = [
            'type' => 'text',
            'text' => '.',
        ];

        $block_two = [
            'type' => 'text',
            'text' => 'the grass is green',
            'citations' => [
                [
                    'type' => 'page_location',
                    'cited_text' => 'The grass is green. ',
                    'document_index' => 0,
                    'document_title' => 'All aboout the grass and the sky',
                    'start_page_number' => 1,
                    'end_page_number' => 2,
                ],
            ],
        ];

        $block_three = [
            'type' => 'text',
            'text' => ' and ',
        ];

        $block_four = [
            'type' => 'text',
            'text' => 'the sky is blue',
            'citations' => [
                [
                    'type' => 'page_location',
                    'cited_text' => 'The sky is blue.',
                    'document_index' => 0,
                    'document_title' => 'All aboout the grass and the sky',
                    'start_page_number' => 1,
                    'end_page_number' => 2,
                ],
            ],
        ];

        $block_five = [
            'type' => 'text',
            'text' => '.',
        ];

        expect(MessageMap::map([
            new AssistantMessage(
                content: 'According to the text, the grass is green and the sky is blue.',
                additionalContent: [
                    'messagePartsWithCitations' => [
                        MessagePartWithCitations::fromContentBlock($block_one),
                        MessagePartWithCitations::fromContentBlock($block_two),
                        MessagePartWithCitations::fromContentBlock($block_three),
                        MessagePartWithCitations::fromContentBlock($block_four),
                        MessagePartWithCitations::fromContentBlock($block_five),
                    ],
                ]
            ),
        ]))->toBe([[
            'role' => 'assistant',
            'content' => [
                $block_one,
                $block_two,
                $block_three,
                $block_four,
                $block_five,
            ],
        ]]);
    });

    it('maps an assistant message with text document citations back to its original format', function (): void {
        $block = [
            'type' => 'text',
            'text' => 'the grass is green',
            'citations' => [
                [
                    'type' => 'char_location',
                    'cited_text' => 'The grass is green. ',
                    'document_index' => 0,
                    'document_title' => 'All aboout the grass and the sky',
                    'start_char_index' => 1,
                    'end_char_index' => 20,
                ],
            ],
        ];

        expect(MessageMap::map([
            new AssistantMessage(
                content: 'According to the text, the grass is green and the sky is blue.',
                additionalContent: [
                    'messagePartsWithCitations' => [
                        MessagePartWithCitations::fromContentBlock($block),
                    ],
                ]
            ),
        ]))->toBe([[
            'role' => 'assistant',
            'content' => [
                $block,
            ],
        ]]);
    });

    it('maps an assistant message with custom content document citations back to its original format', function (): void {
        $block = [
            'type' => 'text',
            'text' => 'the grass is green',
            'citations' => [
                [
                    'type' => 'content_block_location',
                    'cited_text' => 'The grass is green. ',
                    'document_index' => 0,
                    'document_title' => 'All aboout the grass and the sky',
                    'start_block_index' => 0,
                    'end_block_index' => 1,
                ],
            ],
        ];

        expect(MessageMap::map([
            new AssistantMessage(
                content: 'According to the text, the grass is green and the sky is blue.',
                additionalContent: [
                    'messagePartsWithCitations' => [
                        MessagePartWithCitations::fromContentBlock($block),
                    ],
                ]
            ),
        ]))->toBe([[
            'role' => 'assistant',
            'content' => [
                $block,
            ],
        ]]);
    });
});
