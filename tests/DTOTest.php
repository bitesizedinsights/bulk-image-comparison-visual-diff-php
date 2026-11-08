<?php

declare(strict_types=1);

use ImageComparison\DTO\ComparisonResult;
use ImageComparison\DTO\ImagePair;
use ImageComparison\DTO\ImageSize;
use ImageComparison\DTO\Region;

test('comparison result maps a full fixture', function () {
    $result = ComparisonResult::fromArray(loadFixture('comparison_changed'));

    expect($result->index)->toBe(1)
        ->and($result->label)->toBe('Home page')
        ->and($result->baselineUrl)->toBe('https://example.com/before.png')
        ->and($result->candidateUrl)->toBeNull()
        ->and($result->candidateInput)->toBe('base64 image (48213 bytes)')
        ->and($result->passed)->toBeFalse()
        ->and($result->ssim)->toBe(0.9412)
        ->and($result->similarityThreshold)->toBe(0.98)
        ->and($result->phashDistance)->toBe(4)
        ->and($result->ahashDistance)->toBe(2)
        ->and($result->dhashDistance)->toBe(3)
        ->and($result->hashBits)->toBe(64)
        ->and($result->pixelDiffPercent)->toBe(2.31)
        ->and($result->changedPixels)->toBe(8120)
        ->and($result->regionCount)->toBe(1)
        ->and($result->regions)->toHaveCount(1)
        ->and($result->baselineSize)->toEqual(new ImageSize(1280, 800))
        ->and($result->comparedSize?->getPixelCount())->toBe(1024000)
        ->and($result->diffImageKey)->toBe('diff-00001.png')
        ->and($result->diffImageUrl)->toContain('diff-00001.png')
        ->and($result->error)->toBeNull();
});

test('helpers on a changed pair', function () {
    $result = ComparisonResult::fromArray(loadFixture('comparison_changed'));

    expect($result->isChanged())->toBeTrue()
        ->and($result->isPassed())->toBeFalse()
        ->and($result->isFailed())->toBeFalse()
        ->and($result->getRegionCount())->toBe(1)
        ->and($result->getLargestRegion())->toEqual(new Region(120, 340, 420, 96, 8120))
        ->and($result->getLargestRegion()?->getArea())->toBe(40320);
});

test('helpers on a passed pair', function () {
    $result = ComparisonResult::fromArray(loadFixture('comparison_passed'));

    expect($result->isPassed())->toBeTrue()
        ->and($result->isChanged())->toBeFalse()
        ->and($result->isFailed())->toBeFalse()
        ->and($result->regions)->toBe([])
        ->and($result->getRegionCount())->toBe(0)
        ->and($result->getLargestRegion())->toBeNull()
        ->and($result->diffImageUrl)->toBeNull();
});

test('helpers on a failed pair', function () {
    $result = ComparisonResult::fromArray(loadFixture('comparison_failed'));

    expect($result->isFailed())->toBeTrue()
        ->and($result->error)->toBe('Download failed with HTTP 404')
        ->and($result->isPassed())->toBeFalse()
        ->and($result->isChanged())->toBeFalse()
        ->and($result->passed)->toBeNull()
        ->and($result->ssim)->toBeNull()
        ->and($result->regions)->toBeNull()
        ->and($result->getRegionCount())->toBe(0);
});

test('comparison result needs only an index', function () {
    $result = ComparisonResult::fromArray(loadFixture('comparison_minimal'));

    expect($result->index)->toBe(4)
        ->and($result->label)->toBeNull()
        ->and($result->baselineSize)->toBeNull()
        ->and($result->isFailed())->toBeFalse();
});

test('region count falls back to the regions list', function () {
    $result = ComparisonResult::fromArray(['index' => 1, 'regions' => [
        ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
        ['x' => 5, 'y' => 5, 'width' => 1, 'height' => 1],
    ]]);

    expect($result->getRegionCount())->toBe(2)
        ->and($result->regions[0]->changedPixels)->toBeNull();
});

test('comparison result rejects invalid fields', function (array $data) {
    expect(fn () => ComparisonResult::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
    'missing index' => [[]],
    'string index' => [['index' => '1']],
    'passed not bool' => [['index' => 1, 'passed' => 'yes']],
    'ssim not number' => [['index' => 1, 'ssim' => 'high']],
    'regions not list of objects' => [['index' => 1, 'regions' => ['x']]],
    'region without width' => [['index' => 1, 'regions' => [['x' => 1, 'y' => 1, 'height' => 1]]]],
    'size not object' => [['index' => 1, 'baseline_size' => 'big']],
]);

test('image pair converts to input', function () {
    $pair = new ImagePair(
        'https://example.com/a.png',
        'https://example.com/b.png',
        'Home',
        [new Region(0, 0, 300, 90)],
    );

    expect($pair->toArray())->toBe([
        'baseline' => 'https://example.com/a.png',
        'candidate' => 'https://example.com/b.png',
        'label' => 'Home',
        'ignoreRegions' => [['x' => 0, 'y' => 0, 'width' => 300, 'height' => 90]],
    ]);
});

test('image pair omits empty options', function () {
    expect((new ImagePair('a', 'b'))->toArray())->toBe(['baseline' => 'a', 'candidate' => 'b']);
});
