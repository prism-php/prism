<?php

use Prism\Prism\Moderation\Response;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ModerationResult;

describe('isFlagged', function (): void {
    it('returns true when at least one result is flagged', function (): void {
        $result1 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $result2 = new ModerationResult(
            flagged: true,
            categories: ['hate' => true],
            categoryScores: ['hate' => 0.9]
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2], meta: $meta);

        expect($response->isFlagged())->toBeTrue();
    });

    it('returns false when no results are flagged', function (): void {
        $result1 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $result2 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2], meta: $meta);

        expect($response->isFlagged())->toBeFalse();
    });

    it('returns false when results array is empty', function (): void {
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [], meta: $meta);

        expect($response->isFlagged())->toBeFalse();
    });

    it('works with results created from real API response data', function (): void {
        // Simulating real API response where results don't have an id field
        $resultData = [
            'flagged' => true,
            'categories' => [
                'hate' => true,
                'violence' => false,
            ],
            'category_scores' => [
                'hate' => 0.9,
                'violence' => 0.1,
            ],
        ];

        $result = ModerationResult::fromArray($resultData);
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result], meta: $meta);

        expect($response->isFlagged())->toBeTrue();
    });
});

describe('firstFlagged', function (): void {
    it('returns the first flagged result', function (): void {
        $result1 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $result2 = new ModerationResult(
            flagged: true,
            categories: ['hate' => true],
            categoryScores: ['hate' => 0.9]
        );
        $result3 = new ModerationResult(
            flagged: true,
            categories: ['violence' => true],
            categoryScores: ['violence' => 0.8]
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2, $result3], meta: $meta);

        $firstFlagged = $response->firstFlagged();

        expect($firstFlagged)->not->toBeNull();
        expect($firstFlagged->flagged)->toBeTrue();
        expect($firstFlagged->categories['hate'])->toBeTrue();
    });

    it('returns null when no results are flagged', function (): void {
        $result1 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $result2 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2], meta: $meta);

        expect($response->firstFlagged())->toBeNull();
    });

    it('returns null when results array is empty', function (): void {
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [], meta: $meta);

        expect($response->firstFlagged())->toBeNull();
    });
});

describe('flagged', function (): void {
    it('returns all flagged results', function (): void {
        $result1 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $result2 = new ModerationResult(
            flagged: true,
            categories: ['hate' => true],
            categoryScores: ['hate' => 0.9]
        );
        $result3 = new ModerationResult(
            flagged: true,
            categories: ['violence' => true],
            categoryScores: ['violence' => 0.8]
        );
        $result4 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2, $result3, $result4], meta: $meta);

        $flagged = $response->flagged();

        expect($flagged)->toHaveCount(2);
        expect($flagged[0]->flagged)->toBeTrue();
        expect($flagged[0]->categories['hate'])->toBeTrue();
        expect($flagged[1]->flagged)->toBeTrue();
        expect($flagged[1]->categories['violence'])->toBeTrue();
    });

    it('returns empty array when no results are flagged', function (): void {
        $result1 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $result2 = new ModerationResult(
            flagged: false,
            categories: [],
            categoryScores: []
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2], meta: $meta);

        $flagged = $response->flagged();

        expect($flagged)->toBeArray();
        expect($flagged)->toHaveCount(0);
    });

    it('returns empty array when results array is empty', function (): void {
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [], meta: $meta);

        $flagged = $response->flagged();

        expect($flagged)->toBeArray();
        expect($flagged)->toHaveCount(0);
    });

    it('returns all results when all are flagged', function (): void {
        $result1 = new ModerationResult(
            flagged: true,
            categories: ['hate' => true],
            categoryScores: ['hate' => 0.9]
        );
        $result2 = new ModerationResult(
            flagged: true,
            categories: ['violence' => true],
            categoryScores: ['violence' => 0.8]
        );
        $meta = new Meta(id: 'modr-4913', model: 'omni-moderation-latest');
        $response = new Response(results: [$result1, $result2], meta: $meta);

        $flagged = $response->flagged();

        expect($flagged)->toHaveCount(2);
        expect($flagged[0]->flagged)->toBeTrue();
        expect($flagged[1]->flagged)->toBeTrue();
    });
});
