<?php

namespace App\Exercise;

/**
 * setly-phase-exercise-media.md §4 / claude-code-prompt-exercise-media.md
 * task 2: GD-based (not Imagick — neither was installed in this codebase;
 * GD needs no extra system libs beyond libwebp for WebP support, already
 * confirmed present after the Dockerfile's `install-php-extensions gd`).
 * Pure function: bytes in, bytes out — no Flysystem/filesystem I/O here,
 * so this is unit-testable without touching disk. The import command
 * owns writing the result to storage.
 */
class ExerciseImageProcessor
{
    public const POSTER_MAX_DIMENSION = 300;
    public const POSTER_QUALITY = 80;
    public const DETAIL_MAX_DIMENSION = 600;
    public const DETAIL_QUALITY = 85;

    /** @throws \RuntimeException if the source bytes aren't a decodable image */
    public function toPoster(string $sourceJpegBytes): ProcessedImage
    {
        return $this->resize($sourceJpegBytes, self::POSTER_MAX_DIMENSION, self::POSTER_QUALITY);
    }

    /** @throws \RuntimeException if the source bytes aren't a decodable image */
    public function toDetail(string $sourceJpegBytes): ProcessedImage
    {
        return $this->resize($sourceJpegBytes, self::DETAIL_MAX_DIMENSION, self::DETAIL_QUALITY);
    }

    private function resize(string $sourceBytes, int $maxDimension, int $quality): ProcessedImage
    {
        $source = @imagecreatefromstring($sourceBytes);
        if ($source === false) {
            throw new \RuntimeException('Source bytes could not be decoded as an image.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1.0, $maxDimension / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $resized = imagecreatetruecolor($width, $height);
        // Flatten onto white — the source JPEGs have no alpha channel, but
        // WebP output otherwise defaults to a black background for any
        // edge-antialiasing transparency imagecopyresampled introduces.
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        ob_start();
        imagewebp($resized, null, $quality);
        $binary = ob_get_clean();

        if ($binary === false || $binary === '') {
            throw new \RuntimeException('WebP encoding failed.');
        }

        return new ProcessedImage($binary, $width, $height);
    }
}
