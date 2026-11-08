<?php

declare(strict_types=1);

namespace ImageComparison;

enum ResizeMode: string
{
    case CandidateToBaseline = 'candidate-to-baseline';
    case None = 'none';
}
