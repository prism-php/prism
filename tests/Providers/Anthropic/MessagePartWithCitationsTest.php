<?php

use Prism\Prism\Providers\Anthropic\ValueObjects\MessagePartWithCitations;

it('works with no text', function (): void {
    $part = MessagePartWithCitations::fromContentBlock([
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
    ]);

    expect($part->text)->toBeNull();
    expect($part->citations)->toHaveCount(1);
    expect($part->citations[0]->type)->toBe('page_location');
});

it('works with web search', function (): void {
    $part = MessagePartWithCitations::fromContentBlock([
        'citations' => [
            [
                'type' => 'web_search_result_location',
                'cited_text' => 'Laravel 12 has been released. It introduces new starter kits for React, Vue, and Livewire and updates the latest upstream dependencies.',
                'url' => 'https://laravel-news.com/laravel-12',
                'title' => 'Laravel 12 is Now Released - Laravel News',
            ],
        ],
    ]);

    expect($part->text)->toBeNull();
    expect($part->citations)->toHaveCount(1);
    expect($part->citations[0]->type)->toBe('web_search_result_location');
    expect($part->citations[0]->citedText)->toContain('Laravel 12 has been released');
    expect($part->citations[0]->documentTitle)->toContain('Laravel 12 is Now');
    expect($part->citations[0]->url)->toBe('https://laravel-news.com/laravel-12');
});
