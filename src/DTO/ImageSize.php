<?php

declare(strict_types=1);

namespace ImageComparison\DTO;

use ImageComparison\Support\Value;

final readonly class ImageSize implements \JsonSerializable
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

    /** @return array{width: int, height: int} */
    public function toArray(): array
    {
        return ['width' => $this->width, 'height' => $this->height];
    }

    /** @return array{width: int, height: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getPixelCount(): int
    {
        return $this->width * $this->height;
    }
}
