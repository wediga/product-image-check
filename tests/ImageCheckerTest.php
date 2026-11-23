<?php

declare(strict_types=1);

namespace ProductImageCheck\Tests;

use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImageCheck\ImageChecker;

final class ImageCheckerTest extends TestCase
{
    private const int SIZE = ImageChecker::MIN_SIDE_PX;
    private const int LAST = self::SIZE - 1;
    private const int WIDE = self::SIZE * 2;
    private const int MIN_MARGIN = 50;
    private const int MIN_MARGIN_WIDE = 100;
    private const int FAR_END = self::LAST - self::MIN_MARGIN;
    private const int JPEG_ARTIFACT_BUFFER = 10;
    private const int CENTER = 500;
    private const int GRAY = 128;
    private const int TRANSPARENT = 127;
    private const int EDGE_PIXELS = 3996;
    private const int WHITE_EDGE_PIXELS_BELOW_HALF = 1997;
    private const int PERCENT = 100;
    private const int BAND_INSET = 10;
    private const int BAND_HEIGHT = 5;
    private const int SIDES_CROSSED_BY_BAND = 3;
    private const int SPECK_START = 3;
    private const int DUST_SIDE = 2;
    private const int SMALLEST_VISIBLE_SIDE = 3;
    private const float KEPT_FILE_SHARE = 0.4;
    private const int PNG_HEADER_CHUNK_LENGTH = 13;
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const string PNG_HEADER_CHUNK_TYPE = 'IHDR';
    private const string PNG_BIT_DEPTH_AND_COLOR_TYPE = "\x08\x06\x00\x00\x00";
    private const string PNG_INVALID_CRC = "\x00\x00\x00\x00";

    private string $directory;
    private ImageChecker $checker;

    protected function setUp(): void
    {
        $this->directory = TestImages::createDirectory();
        $this->checker = new ImageChecker();
    }

    protected function tearDown(): void
    {
        TestImages::removeDirectory($this->directory);
    }

    public function testProductWithExactMinimumMarginsHasNoProblems(): void
    {
        $this->assertSame([], $this->checkPng($this->imageWithProduct()));
    }

    public function testCleanJpegHasNoProblems(): void
    {
        $path = $this->directory . '/image.jpg';
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        $margin = self::MIN_MARGIN + self::JPEG_ARTIFACT_BUFFER;
        TestImages::drawProduct($image, $margin, $margin, self::LAST - $margin, self::LAST - $margin);
        imagejpeg($image, $path);

        $this->assertSame([], $this->checker->check($path));
    }

    public function testPaletteImageIsCheckedByColorInsteadOfIndex(): void
    {
        $image = imagecreate(self::SIZE, self::SIZE);
        imagecolorallocate($image, TestImages::WHITE, TestImages::WHITE, TestImages::WHITE);
        TestImages::drawProduct($image, self::MIN_MARGIN, self::MIN_MARGIN, self::FAR_END, self::FAR_END);

        $this->assertSame([], $this->checkPng($image));
    }

    public static function tooSmallSizes(): array
    {
        return [
            'too narrow' => [self::SIZE - 1, self::SIZE],
            'too short' => [self::SIZE, self::SIZE - 1],
        ];
    }

    #[DataProvider('tooSmallSizes')]
    public function testTooSmallImageIsReported(int $width, int $height): void
    {
        $image = TestImages::whiteCanvas($width, $height);
        TestImages::drawProduct($image, self::MIN_MARGIN, self::MIN_MARGIN, self::CENTER, self::CENTER);

        $expected = sprintf(ImageChecker::RESOLUTION_TOO_LOW, $width, $height, ImageChecker::MIN_SIDE_PX);

        $this->assertSame([$expected], $this->checkPng($image));
    }

    public function testColoredBackgroundIsReportedWithoutMarginProblems(): void
    {
        $image = $this->filledCanvas(self::GRAY);

        $this->assertSame([sprintf(ImageChecker::EDGE_NOT_WHITE, 0)], $this->checkPng($image));
    }

    public function testBackgroundAtWhiteThresholdCountsAsWhite(): void
    {
        $image = $this->filledCanvas(ImageChecker::WHITE_THRESHOLD);

        $this->assertSame([ImageChecker::NO_PRODUCT], $this->checkPng($image));
    }

    public function testBackgroundJustBelowWhiteThresholdIsReported(): void
    {
        $image = $this->filledCanvas(ImageChecker::WHITE_THRESHOLD - 1);

        $this->assertSame([sprintf(ImageChecker::EDGE_NOT_WHITE, 0)], $this->checkPng($image));
    }

    public function testExactlyHalfWhiteEdgeReachesTheMarginCheck(): void
    {
        $expected = [
            sprintf(ImageChecker::MARGIN_TOO_SMALL, 0, ImageChecker::SIDE_RIGHT, self::MIN_MARGIN),
            sprintf(ImageChecker::MARGIN_TOO_SMALL, 0, ImageChecker::SIDE_TOP, self::MIN_MARGIN),
        ];

        $this->assertSame($expected, $this->checkPng($this->imageWithHalfBlackEdge()));
    }

    public function testLessThanHalfWhiteEdgeIsReported(): void
    {
        $image = $this->imageWithHalfBlackEdge();
        imagesetpixel($image, self::LAST, self::LAST, imagecolorallocate($image, 0, 0, 0));

        $share = self::WHITE_EDGE_PIXELS_BELOW_HALF / self::EDGE_PIXELS * self::PERCENT;

        $this->assertSame([sprintf(ImageChecker::EDGE_NOT_WHITE, $share)], $this->checkPng($image));
    }

    public function testProductFillingTheFrameIsReportedAsEdgeProblem(): void
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        TestImages::drawProduct($image, 0, 0, self::LAST, self::LAST);

        $this->assertSame([sprintf(ImageChecker::EDGE_NOT_WHITE, 0)], $this->checkPng($image));
    }

    public function testProductTouchingOneEdgeIsReportedAsMargin(): void
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        TestImages::drawProduct($image, 0, self::MIN_MARGIN, self::CENTER, self::FAR_END);

        $expected = sprintf(ImageChecker::MARGIN_TOO_SMALL, 0, ImageChecker::SIDE_LEFT, self::MIN_MARGIN);

        $this->assertSame([$expected], $this->checkPng($image));
    }

    public static function marginsOneBelowMinimum(): array
    {
        $near = self::MIN_MARGIN;
        $far = self::FAR_END;

        return [
            'left' => [ImageChecker::SIDE_LEFT, $near - 1, $near, $far, $far],
            'right' => [ImageChecker::SIDE_RIGHT, $near, $near, $far + 1, $far],
            'top' => [ImageChecker::SIDE_TOP, $near, $near - 1, $far, $far],
            'bottom' => [ImageChecker::SIDE_BOTTOM, $near, $near, $far, $far + 1],
        ];
    }

    #[DataProvider('marginsOneBelowMinimum')]
    public function testMarginOneBelowMinimumIsReported(string $side, int $left, int $top, int $right, int $bottom): void
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        TestImages::drawProduct($image, $left, $top, $right, $bottom);

        $expected = sprintf(ImageChecker::MARGIN_TOO_SMALL, self::MIN_MARGIN - 1, $side, self::MIN_MARGIN);

        $this->assertSame([$expected], $this->checkPng($image));
    }

    public function testMarginIsMeasuredAgainstTheMatchingImageSide(): void
    {
        $image = TestImages::whiteCanvas(self::WIDE, self::SIZE);
        TestImages::drawProduct($image, self::MIN_MARGIN, self::MIN_MARGIN, self::SIZE, self::FAR_END);

        $expected = sprintf(
            ImageChecker::MARGIN_TOO_SMALL,
            self::MIN_MARGIN,
            ImageChecker::SIDE_LEFT,
            self::MIN_MARGIN_WIDE,
        );

        $this->assertSame([$expected], $this->checkPng($image));
    }

    public function testTransparentBackgroundCountsAsWhite(): void
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, self::TRANSPARENT));
        TestImages::drawProduct($image, self::MIN_MARGIN, self::MIN_MARGIN, self::FAR_END, self::FAR_END);

        $this->assertSame([], $this->checkPng($image));
    }

    public static function bandColors(): array
    {
        $level = ImageChecker::SHADOW_MIN_LEVEL;
        $chroma = ImageChecker::SHADOW_MAX_CHROMA;

        return [
            'gray at shadow level' => [$level, $level, $level, 0],
            'gray below shadow level' => [$level - 1, $level - 1, $level - 1, self::SIDES_CROSSED_BY_BAND],
            'tint at chroma limit' => [$level + $chroma, $level, $level, 0],
            'tint above chroma limit' => [$level + $chroma + 1, $level, $level, self::SIDES_CROSSED_BY_BAND],
        ];
    }

    #[DataProvider('bandColors')]
    public function testOnlyLightColorlessPixelsCountAsShadow(int $red, int $green, int $blue, int $problems): void
    {
        $image = $this->imageWithProduct();
        imagefilledrectangle(
            $image,
            self::BAND_INSET,
            self::LAST - self::BAND_INSET,
            self::LAST - self::BAND_INSET,
            self::LAST - self::BAND_HEIGHT,
            imagecolorallocate($image, $red, $green, $blue),
        );

        $this->assertCount($problems, $this->checkPng($image));
    }

    public function testDustSpeckDoesNotMoveTheMargins(): void
    {
        $image = $this->imageWithProduct();
        $this->drawSpeck($image, self::DUST_SIDE);

        $this->assertSame([], $this->checkPng($image));
    }

    public function testBlobAtLineMinimumCountsAsProduct(): void
    {
        $image = $this->imageWithProduct();
        $this->drawSpeck($image, self::SMALLEST_VISIBLE_SIDE);

        $expected = [
            sprintf(ImageChecker::MARGIN_TOO_SMALL, self::SPECK_START, ImageChecker::SIDE_LEFT, self::MIN_MARGIN),
            sprintf(ImageChecker::MARGIN_TOO_SMALL, self::SPECK_START, ImageChecker::SIDE_TOP, self::MIN_MARGIN),
        ];

        $this->assertSame($expected, $this->checkPng($image));
    }

    public function testImageWithOnlyDustHasNoProduct(): void
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        $this->drawSpeck($image, self::DUST_SIDE);

        $this->assertSame([ImageChecker::NO_PRODUCT], $this->checkPng($image));
    }

    public static function unreadableContents(): array
    {
        return [
            'empty' => [''],
            'one byte' => ['x'],
            'not an image' => ['not an image'],
        ];
    }

    #[DataProvider('unreadableContents')]
    public function testBrokenFileIsReportedAsUnreadable(string $contents): void
    {
        $path = $this->directory . '/broken.png';
        file_put_contents($path, $contents);

        $this->assertSame([ImageChecker::UNREADABLE], $this->checker->check($path));
    }

    public function testMissingFileIsReportedAsUnreadable(): void
    {
        $this->assertSame([ImageChecker::UNREADABLE], $this->checker->check($this->directory . '/missing.png'));
    }

    public function testTruncatedJpegIsReportedAsUnreadable(): void
    {
        $path = $this->directory . '/cut.jpg';
        imagejpeg($this->imageWithProduct(), $path);
        $this->truncate($path);

        $this->assertSame([ImageChecker::UNREADABLE], $this->checker->check($path));
    }

    public function testTruncatedPngIsReportedAsUnreadable(): void
    {
        $path = $this->directory . '/cut.png';
        imagepng($this->imageWithProduct(), $path);
        $this->truncate($path);

        $this->assertSame([ImageChecker::UNREADABLE], $this->checker->check($path));
    }

    public function testImageAbovePixelLimitIsRejectedWithoutDecoding(): void
    {
        $path = $this->directory . '/huge.png';
        file_put_contents($path, $this->pngHeader(ImageChecker::MAX_PIXELS + 1, 1));

        $expected = sprintf(ImageChecker::TOO_LARGE, ImageChecker::MAX_PIXELS + 1, 1);

        $this->assertSame([$expected], $this->checker->check($path));
    }

    public function testImageAtPixelLimitPassesTheSizeGuard(): void
    {
        $path = $this->directory . '/limit.png';
        file_put_contents($path, $this->pngHeader(ImageChecker::MAX_PIXELS, 1));

        $this->assertSame([ImageChecker::UNREADABLE], $this->checker->check($path));
    }

    private function imageWithProduct(): GdImage
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        TestImages::drawProduct($image, self::MIN_MARGIN, self::MIN_MARGIN, self::FAR_END, self::FAR_END);

        return $image;
    }

    private function filledCanvas(int $level): GdImage
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($image, 0, 0, imagecolorallocate($image, $level, $level, $level));

        return $image;
    }

    private function imageWithHalfBlackEdge(): GdImage
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        $black = imagecolorallocate($image, 0, 0, 0);
        imageline($image, 0, 0, self::LAST, 0, $black);
        imageline($image, self::LAST, 1, self::LAST, self::LAST - 1, $black);

        return $image;
    }

    private function drawSpeck(GdImage $image, int $side): void
    {
        $end = self::SPECK_START + $side - 1;
        TestImages::drawProduct($image, self::SPECK_START, self::SPECK_START, $end, $end);
    }

    private function truncate(string $path): void
    {
        $contents = file_get_contents($path);
        file_put_contents($path, substr($contents, 0, (int) (strlen($contents) * self::KEPT_FILE_SHARE)));
    }

    private function checkPng(GdImage $image): array
    {
        $path = $this->directory . '/image.png';
        imagepng($image, $path);

        return $this->checker->check($path);
    }

    // Only the header, so the size guard can be tested without allocating a huge image.
    private function pngHeader(int $width, int $height): string
    {
        return self::PNG_SIGNATURE
            . pack('N', self::PNG_HEADER_CHUNK_LENGTH)
            . self::PNG_HEADER_CHUNK_TYPE
            . pack('NN', $width, $height)
            . self::PNG_BIT_DEPTH_AND_COLOR_TYPE
            . self::PNG_INVALID_CRC;
    }
}
