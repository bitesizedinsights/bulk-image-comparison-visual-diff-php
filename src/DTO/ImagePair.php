<?php

declare(strict_types=1);

namespace ImageComparison\DTO;

/**
 * One baseline and candidate image to compare.
 */
final readonly class ImagePair
{
    /**
     * @param  string  $baseline  Reference image: http(s) URL, data URI or raw base64, 25 MB max.
     * @param  string  $candidate  Image to check against the baseline, same forms, 25 MB max.
     * @param  string|null  $label  Name echoed back in the result.
     * @param  list<Region>  $ignoreRegions  Areas in baseline pixels to skip for this pair.
     */
    public function __construct(
        public string $baseline,
        public string $candidate,
        public ?string $label = null,
        public array $ignoreRegions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['baseline' => $this->baseline, 'candidate' => $this->candidate];

        if ($this->label !== null) {
            $data['label'] = $this->label;
        }

        if ($this->ignoreRegions !== []) {
            $data['ignoreRegions'] = array_map(static fn (Region $r): array => $r->toArray(), $this->ignoreRegions);
        }

        return $data;
    }
}
