<?php

namespace App\Exercise;

/** ExerciseImageProcessor's output — the processed WebP binary plus its final dimensions. */
final class ProcessedImage
{
    public function __construct(
        public readonly string $binary,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
