<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx-FJr'));
});

it('sends the correct basic request structure', function (): void {
    FixtureResponse::fakeResponseSequence('chat/completions', 'perplexity/structured');

    $response = Prism::structured()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Summarize the top 10 posts on Hacker News today')
        ->withSchema(new ObjectSchema(
            name: 'summaries',
            description: 'The summaries of the top posts',
            properties: [
                new ArraySchema(
                    name: 'summaries',
                    description: 'A list of summaries',
                    items: new ObjectSchema(
                        name: 'summary',
                        description: 'A summary of a single post',
                        properties: [
                            new StringSchema('title', 'The title of the post'),
                            new StringSchema('url', 'The URL of the post'),
                            new StringSchema('summary', 'A brief summary of the post'),
                        ],
                        requiredFields: ['title', 'url', 'summary']
                    )
                ),
            ],
            requiredFields: ['summaries']
        ))
        ->asStructured();

    expect($response->usage->promptTokens)->toBe(249)
        ->and($response->usage->completionTokens)->toBe(788)
        ->and($response->usage->cacheWriteInputTokens)->toBeNull()
        ->and($response->usage->cacheReadInputTokens)->toBeNull()
        ->and($response->meta->id)->toBe('68662390-4f30-4205-848f-c2397e23233f')
        ->and($response->meta->model)->toBe('sonar')
        ->and($response->structured)->toBe([
            'summaries' => [
                [
                    'title' => 'AirPods liberated from Apple\'s ecosystem',
                    'url' => 'https://news.ycombinator.com/item?id=45944870',
                    'summary' => 'A project on GitHub demonstrates how to free AirPods from Apple\'s strict ecosystem, allowing more versatile use and customization beyond Apple\'s limitations.',
                ],
                [
                    'title' => 'When UPS charged me a $684 tariff on $355 of vintage electronics',
                    'url' => 'https://news.ycombinator.com/item?id=45944760',
                    'summary' => 'A user shares an experience where UPS unexpectedly imposed a high tariff on a shipment of vintage electronics worth significantly less, discussing the implications of such fees on collectors and sellers.',
                ],
                [
                    'title' => 'Investigation into suspicious pressure on Archive.today',
                    'url' => 'https://news.ycombinator.com/item?id=45944211',
                    'summary' => 'Community discussion about an investigation revealing suspicious external pressures aimed at the web archiving service Archive.today, touching on concerns about internet censorship and data preservation.',
                ],
                [
                    'title' => 'Open Source Bot That Summarizes Top Hacker News Stories',
                    'url' => 'https://news.ycombinator.com/item?id=33748363',
                    'summary' => 'A Show HN post presents an open source bot that automatically summarizes top Hacker News stories using OpenAI\'s GPT-3, sending concise summaries to a Telegram channel to enhance content accessibility.',
                ],
                [
                    'title' => 'Weekly Cybersecurity Recap: Hyper-V Malware and AI Side-Channel Leaks',
                    'url' => 'https://thehackernews.com/2025/11/weekly-recap-hyper-v-malware-malicious.html',
                    'summary' => 'A summary of the week\'s top cybersecurity threats including stealthy malware targeting Hyper-V virtual machines, AI side-channel leaks identifying encrypted chat topics, and exploits targeting popular platforms.',
                ],
                [
                    'title' => 'Iranian Hackers Launch \'SpearSpecter\' Campaign Targeting Defense Officials',
                    'url' => 'https://thehackernews.com/2025/11/iranian-hackers-launch-spearspecter-spy.html',
                    'summary' => 'Report on Iran\'s APT42 group deploying the TAMECAT malware in the \'SpearSpecter\' campaign to spy on defense and government officials, illustrating ongoing geopolitical cyber espionage activities.',
                ],
                [
                    'title' => 'Firefox Expands Fingerprint Protections',
                    'url' => 'https://news.ycombinator.com/item?id=45888891',
                    'summary' => 'Firefox has enhanced its browser fingerprinting protections to better safeguard users from tracking and profiling techniques on the web.',
                ],
                [
                    'title' => 'Meta Replaces WhatsApp for Windows with a Web Wrapper',
                    'url' => 'https://news.ycombinator.com/item?id=45910347',
                    'summary' => 'Meta has replaced the native WhatsApp Windows app with a web wrapper version, sparking debate about performance and user experience implications.',
                ],
                [
                    'title' => 'Google to Allow Sideloading Android Apps Without Verification',
                    'url' => 'https://news.ycombinator.com/item?id=45908938',
                    'summary' => 'Google announced plans to permit users to sideload Android applications without requiring developer verification, raising conversations around app security and user control.',
                ],
                [
                    'title' => 'The Internet Is No Longer a Safe Haven for Software Hobbyists',
                    'url' => 'https://news.ycombinator.com/item?id=45944870',
                    'summary' => 'An opinion piece and community reactions reflecting concerns about increasing challenges and risks for hobbyist developers hosting personal projects online.',
                ],
            ],
        ]);
});
