<?php

declare(strict_types=1);

namespace ImageComparison\DTO;

use ImageComparison\Support\Value;

final readonly class ComparisonResult
{
    /**
     * @param  int  $index  1-based position of the pair in the input.
     * @param  float|null  $ssim  Structural similarity, 0 to 1 (1 is identical).
     * @param  int|null  $phashDistance  Perceptual hash Hamming distance, 0 to 64.
     * @param  float|null  $pixelDiffPercent  Share of pixels that differ beyond the tolerance.
     * @param  list<Region>|null  $regions  Changed areas in baseline pixels, largest first (max 50).
     */
    public function __construct(
        public int $index,
        public ?string $label = null,
        public ?string $baselineUrl = null,
        public ?string $candidateUrl = null,
        public ?string $baselineInput = null,
        public ?string $candidateInput = null,
        public ?bool $passed = null,
        public ?float $ssim = null,
        public ?float $similarityThreshold = null,
        public ?int $phashDistance = null,
        public ?int $ahashDistance = null,
        public ?int $dhashDistance = null,
        public ?int $hashBits = null,
        public ?float $pixelDiffPercent = null,
        public ?int $changedPixels = null,
        public ?int $regionCount = null,
        public ?array $regions = null,
        public ?ImageSize $baselineSize = null,
        public ?ImageSize $candidateSize = null,
        public ?ImageSize $comparedSize = null,
        public ?string $diffImageKey = null,
        public ?string $diffImageUrl = null,
        public ?string $error = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $regions = Value::nullableArray($data, 'regions');

        return new self(
            index: Value::int($data, 'index'),
            label: Value::nullableString($data, 'label'),
            baselineUrl: Value::nullableString($data, 'baseline_url'),
            candidateUrl: Value::nullableString($data, 'candidate_url'),
            baselineInput: Value::nullableString($data, 'baseline_input'),
            candidateInput: Value::nullableString($data, 'candidate_input'),
            passed: Value::nullableBool($data, 'passed'),
            ssim: Value::nullableFloat($data, 'ssim'),
            similarityThreshold: Value::nullableFloat($data, 'similarity_threshold'),
            phashDistance: Value::nullableInt($data, 'phash_distance'),
            ahashDistance: Value::nullableInt($data, 'ahash_distance'),
            dhashDistance: Value::nullableInt($data, 'dhash_distance'),
            hashBits: Value::nullableInt($data, 'hash_bits'),
            pixelDiffPercent: Value::nullableFloat($data, 'pixel_diff_percent'),
            changedPixels: Value::nullableInt($data, 'changed_pixels'),
            regionCount: Value::nullableInt($data, 'region_count'),
            regions: $regions === null ? null : self::regions($regions),
            baselineSize: self::size($data, 'baseline_size'),
            candidateSize: self::size($data, 'candidate_size'),
            comparedSize: self::size($data, 'compared_size'),
            diffImageKey: Value::nullableString($data, 'diff_image_key'),
            diffImageUrl: Value::nullableString($data, 'diff_image_url'),
            error: Value::nullableString($data, 'error'),
        );
    }

    /** The pair could not be compared; no scores are available. */
    public function isFailed(): bool
    {
        return $this->error !== null;
    }

    /** The pair was compared and met the similarity threshold and changed-pixel limit. */
    public function isPassed(): bool
    {
        return $this->passed === true;
    }

    /** The pair was compared and did not pass. */
    public function isChanged(): bool
    {
        return $this->passed === false;
    }

    /** Number of changed regions, 0 when none were found or the pair failed. */
    public function getRegionCount(): int
    {
        return $this->regionCount ?? count($this->regions ?? []);
    }

    /** The largest changed region, or null when nothing changed. */
    public function getLargestRegion(): ?Region
    {
        return $this->regions[0] ?? null;
    }

    /**
     * @param  array<mixed>  $regions
     * @return list<Region>
     */
    private static function regions(array $regions): array
    {
        $result = [];

        foreach (array_values($regions) as $i => $region) {
            if (! is_array($region)) {
                throw new \InvalidArgumentException("Field 'regions' item {$i} must be an object");
            }

            $result[] = Region::fromArray($region);
        }

        return $result;
    }

    /** @param  array<mixed>  $data */
    private static function size(array $data, string $key): ?ImageSize
    {
        $size = Value::nullableArray($data, $key);

        return $size === null ? null : ImageSize::fromArray($size);
    }
}
