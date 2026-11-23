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
    private const int MIN_MARGIN = 50;
    private const int FAR_END = self::LAST - self::MIN_MARGIN;
    private const int CENTER = 500;
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
