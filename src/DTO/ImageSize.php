<?php

declare(strict_types=1);

namespace ImageComparison\DTO;

use ImageComparison\Support\Value;

final readonly class ImageSize
{
    public function __construct(
        public int $width,
        public int $height,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            width: Value::int($data, 'width'),
            height: Value::int($data, 'height'),
        );
    }

    public function getPixelCount(): int
    {
        return $this->width * $this->height;
    }
}
