<?php

declare(strict_types=1);

namespace ImageComparison\DTO;

use ImageComparison\Support\Value;

/**
 * A rectangle in baseline pixels: an area to ignore (input) or a changed area (output).
 */
final readonly class Region
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
        public ?int $changedPixels = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            x: Value::int($data, 'x'),
            y: Value::int($data, 'y'),
            width: Value::int($data, 'width'),
            height: Value::int($data, 'height'),
            changedPixels: Value::nullableInt($data, 'changed_pixels'),
        );
    }

    /** @return array{x: int, y: int, width: int, height: int} */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    /** Area of the rectangle in pixels. */
    public function getArea(): int
    {
        return $this->width * $this->height;
    }
}
