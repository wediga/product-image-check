<?php

declare(strict_types=1);

namespace ProductImageCheck;

use GdImage;

final class ImageChecker
{
    public const int MIN_SIDE_PX = 1000;
    public const int MAX_PIXELS = 50_000_000;

    public const string UNREADABLE = 'File is not a readable JPG or PNG';
    public const string TOO_LARGE = 'Image is too large to check (%d x %d pixels)';
    public const string RESOLUTION_TOO_LOW = 'Resolution %d x %d is too low, each side needs at least %d pixels';

    private const string JPEG_IGNORE_WARNING_SETTING = 'gd.jpeg_ignore_warning';

    public function __construct()
    {
        // With the default setting GD loads truncated JPEG files without any warning.
        ini_set(self::JPEG_IGNORE_WARNING_SETTING, '0');
    }

    public function check(string $path): array
    {
        $size = $this->readWithoutWarnings(static fn (): array|false => getimagesize($path));

        if ($size === null) {
            return [self::UNREADABLE];
        }

        [$width, $height, $type] = $size;

        if ($width * $height > self::MAX_PIXELS) {
            return [sprintf(self::TOO_LARGE, $width, $height)];
        }

        if ($this->load($path, $type) === null) {
            return [self::UNREADABLE];
        }

        return $this->resolutionProblems($width, $height);
    }

    private function load(string $path, int $type): ?GdImage
    {
        $image = $this->readWithoutWarnings(static fn (): GdImage|false => match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            default => false,
        });

        if ($image === null) {
            return null;
        }

        imagepalettetotruecolor($image);

        return $image;
    }

    // Broken files often show up only as a PHP warning, and a truncated JPEG even returns an image.
    private function readWithoutWarnings(callable $read): mixed
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            $result = $read();
        } finally {
            restore_error_handler();
        }

        return $warned || $result === false ? null : $result;
    }

    private function resolutionProblems(int $width, int $height): array
    {
        if (min($width, $height) >= self::MIN_SIDE_PX) {
            return [];
        }

        return [sprintf(self::RESOLUTION_TOO_LOW, $width, $height, self::MIN_SIDE_PX)];
    }
}
