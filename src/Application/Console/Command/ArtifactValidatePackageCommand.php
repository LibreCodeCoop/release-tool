<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Artifact\NextcloudPackageValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'artifact:validate-package', description: 'Validate basic Nextcloud package structure and version.')]
final class ArtifactValidatePackageCommand extends Command
{
    public function __construct(private readonly NextcloudPackageValidator $validator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('artifact', null, InputOption::VALUE_REQUIRED)
            ->addOption('app-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('version', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $artifact = trim((string) $input->getOption('artifact'));
        $appName = trim((string) $input->getOption('app-name'));
        $version = trim((string) $input->getOption('version'));
        if ($artifact === '' || $appName === '' || $version === '') {
            $this->error($output, '--artifact, --app-name and --version are required.');
            return Command::INVALID;
        }

        try {
            $this->validator->validate($artifact, $appName, $version);
        } catch (\Throwable $exception) {
            $this->error($output, $exception->getMessage());
            return Command::INVALID;
        }

        $output->writeln('Validated release artifact: ' . $artifact);
        return Command::SUCCESS;
    }

    private function error(OutputInterface $output, string $message): void
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln($message);
    }
}
