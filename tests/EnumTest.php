<?php

declare(strict_types=1);

use ImageComparison\Normalization;
use ImageComparison\ResizeMode;

test('enum values', function (string $enum, array $values) {
    expect(array_map(static fn ($case) => $case->value, $enum::cases()))->toBe($values);
})->with([
    'resize mode' => [ResizeMode::class, ['candidate-to-baseline', 'none']],
    'normalization' => [Normalization::class, ['none', 'autocontrast', 'grayscale']],
]);

test('unknown enum values give null', function (string $enum) {
    expect($enum::tryFrom('nope'))->toBeNull();
})->with([ResizeMode::class, Normalization::class]);
