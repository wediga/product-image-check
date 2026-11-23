<?php

declare(strict_types=1);

namespace ProductImageCheck\Tests;

use PHPUnit\Framework\TestCase;
use ProductImageCheck\CheckCommand;
use ProductImageCheck\ImageChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandTest extends TestCase
{
    private const int SIZE = ImageChecker::MIN_SIDE_PX;
    private const int MARGIN = 100;
    private const int IMAGE_COUNT = 2;
    private const string CLEAN_IMAGE = 'clean.png';
    private const string BAD_IMAGE = 'too-close.png';
    private const string TEXT_FILE = 'note.txt';

    private string $directory;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->directory = TestImages::createDirectory();
        $this->tester = new CommandTester(new CheckCommand(new ImageChecker()));
    }

    protected function tearDown(): void
    {
        TestImages::removeDirectory($this->directory);
    }

    public function testMissingDirectoryIsInvalid(): void
    {
        $exitCode = $this->execute($this->directory . '/does-not-exist');

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString(CheckCommand::DIRECTORY_ERROR, $this->tester->getDisplay());
    }

    public function testDirectoryWithoutImagesIsInvalid(): void
    {
        file_put_contents($this->directory . '/' . self::TEXT_FILE, 'not an image');

        $exitCode = $this->execute($this->directory);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString(CheckCommand::NO_IMAGES, $this->tester->getDisplay());
    }

    public function testOnlyCleanImagesSucceed(): void
    {
        $this->saveImage(self::CLEAN_IMAGE, self::MARGIN);

        $exitCode = $this->execute($this->directory);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(sprintf(CheckCommand::SUMMARY, 1, 1), $this->tester->getDisplay());
    }

    public function testProblemImageFailsAndIsNamed(): void
    {
        $this->saveImage(self::CLEAN_IMAGE, self::MARGIN);
        $this->saveImage(self::BAD_IMAGE, 0);
        file_put_contents($this->directory . '/' . self::TEXT_FILE, 'ignored');

        $exitCode = $this->execute($this->directory);
        $display = $this->tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(self::BAD_IMAGE, $display);
        $this->assertStringContainsString(sprintf(CheckCommand::SUMMARY, 1, self::IMAGE_COUNT), $display);
        $this->assertStringNotContainsString(self::TEXT_FILE, $display);
    }

    private function execute(string $directory): int
    {
        return $this->tester->execute([CheckCommand::DIRECTORY_ARGUMENT => $directory]);
    }

    private function saveImage(string $name, int $margin): void
    {
        $image = TestImages::whiteCanvas(self::SIZE, self::SIZE);
        $far = self::SIZE - 1 - self::MARGIN;
        TestImages::drawProduct($image, $margin, self::MARGIN, $far, $far);
        imagepng($image, $this->directory . '/' . $name);
    }
}
