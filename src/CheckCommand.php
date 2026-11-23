<?php

declare(strict_types=1);

namespace ProductImageCheck;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'check', description: 'Checks a directory of product images')]
final class CheckCommand extends Command
{
    public const string DIRECTORY_ARGUMENT = 'directory';
    public const string DIRECTORY_ERROR = 'Directory not found or not readable';
    public const string NO_IMAGES = 'No JPG or PNG images found';
    public const string SUMMARY = '%d of %d images passed';

    private const string IMAGE_PATTERN = '/\.(jpe?g|png)$/i';
    private const string RESULT_OK = 'ok';

    public function __construct(private readonly ImageChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(self::DIRECTORY_ARGUMENT, InputArgument::REQUIRED, 'Directory containing the images');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument(self::DIRECTORY_ARGUMENT);

        if (!is_dir($directory) || !is_readable($directory)) {
            $io->error([self::DIRECTORY_ERROR, $directory]);

            return self::INVALID;
        }

        $files = preg_grep(self::IMAGE_PATTERN, scandir($directory) ?: []);

        if ($files === []) {
            $io->warning(self::NO_IMAGES);

            return self::INVALID;
        }

        $rows = [];
        $failed = 0;

        foreach ($files as $file) {
            $problems = $this->checker->check($directory . DIRECTORY_SEPARATOR . $file);
            $failed += (int) ($problems !== []);
            $rows[] = [$file, $problems === [] ? self::RESULT_OK : implode("\n", $problems)];
        }

        $io->table(['File', 'Result'], $rows);
        $io->writeln(sprintf(self::SUMMARY, count($files) - $failed, count($files)));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
