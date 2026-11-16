<?php

declare(strict_types=1);

namespace ImageComparison;

use ImageComparison\DTO\ComparisonResult;

/**
 * Matches the results of `Client::compare()` against the pairs that were sent.
 *
 * The Actor returns one row per processed pair, so there are fewer rows than pairs on the free plan, in TEST runs,
 * after the spending limit, after a shutdown and for duplicate pairs. A row with an `error` (including a
 * `time_limit` error) still counts as processed; only a pair without any row is missing.
 */
final readonly class ResultSet
{
    /** @var list<ComparisonResult> */
    public array $results;

    /** @param  array<ComparisonResult>  $results */
    public function __construct(array $results)
    {
        $this->results = array_values($results);
    }

    /**
     * @param  array<ComparisonResult>  $results
     */
    public static function from(array $results): self
    {
        return new self($results);
    }

    /**
     * Input indexes (1-based, as in `ComparisonResult::$index`) that got no result row, ascending.
     *
     * @return list<int>
     */
    public function missingIndexes(int $inputCount): array
    {
        $seen = [];

        foreach ($this->results as $result) {
            $seen[$result->index] = true;
        }

        $missing = [];

        for ($index = 1; $index <= $inputCount; $index++) {
            if (! isset($seen[$index])) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * The inputs that got no result row, in input order, keeping their original array keys.
     *
     * @template T
     *
     * @param  array<array-key, T>  $inputs  The pairs passed to `compare()`.
     * @return array<array-key, T>
     */
    public function unprocessed(array $inputs): array
    {
        $missing = array_flip($this->missingIndexes(count($inputs)));
        $unprocessed = [];
        $position = 0;

        foreach ($inputs as $key => $input) {
            $position++;

            if (isset($missing[$position])) {
                $unprocessed[$key] = $input;
            }
        }

        return $unprocessed;
    }

    /** True when every one of the input pairs has a result row. */
    public function isComplete(int $inputCount): bool
    {
        return $this->missingIndexes($inputCount) === [];
    }
}
