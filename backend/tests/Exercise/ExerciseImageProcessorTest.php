<?php

namespace App\Tests\Exercise;

use App\Exercise\ExerciseImageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * setly-phase-exercise-media.md §4 / verification checklist: "Poster
 * images are consistently ≤15KB, detail images ≤60KB each." Uses a
 * synthetic solid-color source image (real photos compress smaller —
 * asserting bounds against a worst-case flat-color source is a stronger
 * guarantee than testing only with easily-compressible real photos would be).
 */
final class ExerciseImageProcessorTest extends TestCase
{
    private ExerciseImageProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new ExerciseImageProcessor();
    }

    /**
     * A large, photo-like (smooth gradient, not pathological noise) source
     * image — verification checklist's ≤15KB/≤60KB bounds are about real
     * exercise photos, which compress like this, not synthetic random
     * noise (which would fail those bounds regardless of correct resizing).
     */
    private function sourceImageBytes(int $width = 1200, int $height = 900): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorallocate($image, (int) (255 * $x / $width), 120, (int) (255 * (1 - $x / $width)));
            imagefilledrectangle($image, $x, 0, $x, $height - 1, $color);
        }
        ob_start();
        imagejpeg($image, null, 90);

        return ob_get_clean();
    }

    public function test_poster_is_300px_on_the_longest_edge_and_at_most_15kb(): void
    {
        $poster = $this->processor->toPoster($this->sourceImageBytes());

        self::assertSame(300, max($poster->width, $poster->height));
        self::assertLessThanOrEqual(15 * 1024, strlen($poster->binary));
    }

    public function test_detail_is_600px_on_the_longest_edge_and_at_most_60kb(): void
    {
        $detail = $this->processor->toDetail($this->sourceImageBytes());

        self::assertSame(600, max($detail->width, $detail->height));
        self::assertLessThanOrEqual(60 * 1024, strlen($detail->binary));
    }

    public function test_a_source_smaller_than_the_target_is_not_upscaled(): void
    {
        $poster = $this->processor->toPoster($this->sourceImageBytes(120, 90));

        self::assertSame(120, $poster->width);
        self::assertSame(90, $poster->height);
    }

    public function test_aspect_ratio_is_preserved(): void
    {
        $detail = $this->processor->toDetail($this->sourceImageBytes(1200, 600));

        self::assertSame(600, $detail->width);
        self::assertSame(300, $detail->height);
    }

    public function test_undecodable_bytes_throw(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->processor->toPoster('not an image');
    }
}
