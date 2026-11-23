<?php

declare(strict_types=1);

namespace ProductImageCheck\Tests;

use GdImage;

final class TestImages
{
    public const int WHITE = 255;

    public static function createDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('product-image-check-', true);
        mkdir($directory);

        return $directory;
    }

    public static function removeDirectory(string $directory): void
    {
        array_map(unlink(...), glob($directory . '/*') ?: []);
        rmdir($directory);
    }

    public static function whiteCanvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, self::WHITE, self::WHITE, self::WHITE));

        return $image;
    }

    public static function drawProduct(GdImage $image, int $left, int $top, int $right, int $bottom): void
    {
        imagefilledrectangle($image, $left, $top, $right, $bottom, imagecolorallocate($image, 0, 0, 0));
    }
}
