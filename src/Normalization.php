<?php

declare(strict_types=1);

namespace ImageComparison;

enum Normalization: string
{
    case None = 'none';
    case Autocontrast = 'autocontrast';
    case Grayscale = 'grayscale';
}
