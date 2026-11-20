<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
readonly class StreamFailureUsage implements Arrayable, JsonSerializable
{
    public string $status;

    public string $scope;

    public bool $includesCompletedSteps;

    /** @param array<array-key, string> $reportedFields */
    public function __construct(
        public Usage $usage,
        public int $stepIndex,
        public array $reportedFields,
    ) {
        if ($stepIndex < 0 || $reportedFields === [] || ! array_is_list($reportedFields) || count(array_unique($reportedFields)) !== count($reportedFields) || array_diff($reportedFields, array_keys($usage->toArray())) !== []) {
            throw new InvalidArgumentException('Invalid stream failure usage fields.');
        }

        $this->status = 'provisional';
        $this->scope = 'attempt';
        $this->includesCompletedSteps = false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'usage' => $this->usage->toArray(),
            'status' => $this->status,
            'scope' => $this->scope,
            'step_index' => $this->stepIndex,
            'reported_fields' => $this->reportedFields,
            'includes_completed_steps' => $this->includesCompletedSteps,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
