<?php

declare(strict_types=1);

namespace ProductImageCheck;

use GdImage;
use Generator;

final class ImageChecker
{
    public const int MIN_SIDE_PX = 1000;
    public const int MAX_PIXELS = 50_000_000;
    public const int WHITE_THRESHOLD = 245;

    public const string UNREADABLE = 'File is not a readable JPG or PNG';
    public const string TOO_LARGE = 'Image is too large to check (%d x %d pixels)';
    public const string RESOLUTION_TOO_LOW = 'Resolution %d x %d is too low, each side needs at least %d pixels';
    public const string EDGE_NOT_WHITE = 'Only %.1f %% of the edge is white or transparent, wrong background or product fills the frame';
    public const string NO_PRODUCT = 'No product found, the image is empty';
    public const string MARGIN_TOO_SMALL = 'Product is only %d pixels from the %s edge, at least %d required';

    public const string SIDE_LEFT = 'left';
    public const string SIDE_RIGHT = 'right';
    public const string SIDE_TOP = 'top';
    public const string SIDE_BOTTOM = 'bottom';

    // A product touching one side never covers half of the edge, so it still reaches the margin check.
    private const float MIN_WHITE_EDGE_SHARE = 0.5;

    private const int MIN_MARGIN_PERCENT = 5;
    private const int PERCENT = 100;
    private const int FULLY_TRANSPARENT = 127;
    private const int ALPHA_SHIFT = 24;
    private const int RED_SHIFT = 16;
    private const int GREEN_SHIFT = 8;
    private const int CHANNEL_MASK = 0xFF;
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

        $image = $this->load($path, $type);

        if ($image === null) {
            return [self::UNREADABLE];
        }

        return [
            ...$this->resolutionProblems($width, $height),
            ...$this->contentProblems($image, $width, $height),
        ];
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

    private function contentProblems(GdImage $image, int $width, int $height): array
    {
        $whiteShare = $this->whiteEdgeShare($image, $width, $height);

        if ($whiteShare < self::MIN_WHITE_EDGE_SHARE) {
            return [sprintf(self::EDGE_NOT_WHITE, $whiteShare * self::PERCENT)];
        }

        $margins = $this->productMargins($image, $width, $height);

        if ($margins === null) {
            return [self::NO_PRODUCT];
        }

        return $this->marginProblems($margins);
    }

    private function whiteEdgeShare(GdImage $image, int $width, int $height): float
    {
        $total = 0;
        $white = 0;

        foreach ($this->edgePixels($width, $height) as [$x, $y]) {
            $total++;
            $white += (int) $this->isWhiteOrTransparent(imagecolorat($image, $x, $y));
        }

        return $white / $total;
    }

    private function edgePixels(int $width, int $height): Generator
    {
        for ($x = 0; $x < $width; $x++) {
            yield [$x, 0];
            yield [$x, $height - 1];
        }

        for ($y = 1; $y < $height - 1; $y++) {
            yield [0, $y];
            yield [$width - 1, $y];
        }
    }

    private function productMargins(GdImage $image, int $width, int $height): ?array
    {
        $rowCounts = array_fill(0, $height, 0);
        $columnCounts = array_fill(0, $width, 0);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);

                if ($this->isWhiteOrTransparent($color)) {
                    continue;
                }

                $rowCounts[$y]++;
                $columnCounts[$x]++;
            }
        }

        $rows = array_keys(array_filter($rowCounts));
        $columns = array_keys(array_filter($columnCounts));

        if ($rows === [] || $columns === []) {
            return null;
        }

        return [
            [self::SIDE_LEFT, $columns[0], $width],
            [self::SIDE_RIGHT, $width - 1 - end($columns), $width],
            [self::SIDE_TOP, $rows[0], $height],
            [self::SIDE_BOTTOM, $height - 1 - end($rows), $height],
        ];
    }

    private function marginProblems(array $margins): array
    {
        $problems = [];

        foreach ($margins as [$side, $margin, $imageLength]) {
            $minimum = (int) ceil($imageLength * self::MIN_MARGIN_PERCENT / self::PERCENT);

            if ($margin < $minimum) {
                $problems[] = sprintf(self::MARGIN_TOO_SMALL, $margin, $side, $minimum);
            }
        }

        return $problems;
    }

    private function isWhiteOrTransparent(int $color): bool
    {
        return $this->isTransparent($color) || min($this->channels($color)) >= self::WHITE_THRESHOLD;
    }

    private function isTransparent(int $color): bool
    {
        return $color >> self::ALPHA_SHIFT === self::FULLY_TRANSPARENT;
    }

    private function channels(int $color): array
    {
        return [
            $color >> self::RED_SHIFT & self::CHANNEL_MASK,
            $color >> self::GREEN_SHIFT & self::CHANNEL_MASK,
            $color & self::CHANNEL_MASK,
        ];
    }
}
