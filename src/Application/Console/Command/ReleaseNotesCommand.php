<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Console\Port\ActionEnvironment;
use LibreCode\ReleaseTool\Application\ReleaseNotes\ReleaseNotesGenerator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:notes', description: 'Build release-note changes from commits and associated pull requests.')]
final class ReleaseNotesCommand extends Command
{
    public function __construct(
        private readonly ReleaseNotesGenerator $generator,
        private readonly ActionEnvironment $actionEnvironment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->addOption('working-directory', null, InputOption::VALUE_REQUIRED, default: '.')
            ->addOption('from-ref', null, InputOption::VALUE_REQUIRED, default: '')
            ->addOption('to-ref', null, InputOption::VALUE_REQUIRED, default: 'HEAD')
            ->addOption('fallback-limit', null, InputOption::VALUE_REQUIRED, default: '10')
            ->addOption('server-url', null, InputOption::VALUE_REQUIRED, default: 'https://github.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $fallbackLimitRaw = trim((string) $input->getOption('fallback-limit'));
            if (filter_var($fallbackLimitRaw, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('fallback-limit must be an integer');
            }
            $fallbackLimit = (int) $fallbackLimitRaw;
            if ($fallbackLimit <= 0) {
                throw new RuntimeException('fallback-limit must be greater than zero');
            }

            $result = $this->generator->generate(
                trim((string) $input->getOption('repository')),
                trim((string) $input->getOption('branch')),
                $this->workingDirectory((string) $input->getOption('working-directory')),
                trim((string) $input->getOption('server-url')),
                trim((string) $input->getOption('from-ref')),
                trim((string) $input->getOption('to-ref')),
                $fallbackLimit,
            );

            $changesFile = $this->writeChanges($result->lines);
            $this->actionEnvironment->output('changes-file', $changesFile);
            $this->actionEnvironment->output('change-count', (string) $result->changeCount());
            $this->actionEnvironment->output('pull-request-count', (string) $result->pullRequestCount);
            $this->actionEnvironment->output('commit-fallback-count', (string) $result->commitFallbackCount);

            $output->writeln(sprintf(
                'Generated %d change entries (%d pull requests, %d direct commits).',
                $result->changeCount(),
                $result->pullRequestCount,
                $result->commitFallbackCount,
            ));
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('::error::' . $exception->getMessage());
            return Command::FAILURE;
        }
    }

    private function workingDirectory(string $value): string
    {
        $value = trim($value);
        return $value === '' ? '.' : $value;
    }

    /** @param list<string> $lines */
    private function writeChanges(array $lines): string
    {
        $directory = getenv('RUNNER_TEMP');
        $directory = is_string($directory) && $directory !== '' ? $directory : sys_get_temp_dir();
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create runner temp directory: %s', $directory));
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'release-note-changes-'
            . bin2hex(random_bytes(12))
            . '.md';
        $content = $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException(sprintf('Could not write release-note changes file: %s', $path));
        }

        return $path;
    }
}
