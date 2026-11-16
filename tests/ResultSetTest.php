<?php

declare(strict_types=1);

use ImageComparison\DTO\ComparisonResult;
use ImageComparison\DTO\ImagePair;
use ImageComparison\ResultSet;

/**
 * @param  list<int>  $indexes
 * @return list<ComparisonResult>
 */
function rowsFor(array $indexes, ?string $error = null): array
{
    return array_map(static fn (int $i): ComparisonResult => new ComparisonResult(index: $i, error: $error), $indexes);
}

/** @return list<ImagePair> */
function pairsOf(int $count): array
{
    return array_map(static fn (int $i): ImagePair => new ImagePair("a{$i}.png", "b{$i}.png"), range(1, $count));
}

test('a complete result has no missing indexes', function () {
    $set = ResultSet::from(rowsFor([1, 2, 3]));

    expect($set->missingIndexes(3))->toBe([])
        ->and($set->isComplete(3))->toBeTrue()
        ->and($set->unprocessed(pairsOf(3)))->toBe([]);
});

test('a truncated result reports the pairs beyond the first 10', function () {
    $set = ResultSet::from(rowsFor(range(1, 10)));
    $pairs = pairsOf(12);

    expect($set->missingIndexes(12))->toBe([11, 12])
        ->and($set->isComplete(12))->toBeFalse()
        ->and($set->unprocessed($pairs))->toBe([10 => $pairs[10], 11 => $pairs[11]]);
});

test('duplicate pairs without a row are reported as missing', function () {
    $set = ResultSet::from(rowsFor([1, 3, 4]));

    expect($set->missingIndexes(4))->toBe([2])
        ->and(array_keys($set->unprocessed(pairsOf(4))))->toBe([1]);
});

test('time_limit rows count as processed', function () {
    $rows = [...rowsFor([1, 2]), ...rowsFor([3], 'time_limit: not started, not enough time left in this run')];
    $set = ResultSet::from($rows);

    expect($set->isComplete(3))->toBeTrue()
        ->and($set->missingIndexes(4))->toBe([4]);
});

test('empty input is complete and an empty result misses every pair', function () {
    expect(ResultSet::from([])->isComplete(0))->toBeTrue()
        ->and(ResultSet::from([])->missingIndexes(0))->toBe([])
        ->and(ResultSet::from([])->unprocessed([]))->toBe([])
        ->and(ResultSet::from([])->missingIndexes(2))->toBe([1, 2]);
});

test('indexes and input keys are preserved regardless of row order', function () {
    $set = ResultSet::from(rowsFor([4, 2]));
    $pairs = pairsOf(4);
    $keyed = ['a' => $pairs[0], 'b' => $pairs[1], 'c' => $pairs[2], 'd' => $pairs[3]];

    expect($set->missingIndexes(4))->toBe([1, 3])
        ->and(array_keys($set->unprocessed($keyed)))->toBe(['a', 'c'])
        ->and($set->results[0]->index)->toBe(4);
});
