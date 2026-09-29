<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Artifact\ArtifactRestorer;
use LibreCode\ReleaseTool\Application\Artifact\ArtifactWorkflowOrigin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'artifact:restore', description: 'Restore an exact GitHub Actions artifact safely.')]
final class ArtifactRestoreCommand extends Command
{
    public function __construct(private readonly ArtifactRestorer $restorer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', null, InputOption::VALUE_REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED)
            ->addOption('expected-head-sha', null, InputOption::VALUE_REQUIRED, default: '')
            ->addOption('destination', null, InputOption::VALUE_REQUIRED)
            ->addOption('expected-event', null, InputOption::VALUE_REQUIRED, default: '')
            ->addOption('expected-workflow-path', null, InputOption::VALUE_REQUIRED, default: '')
            ->addOption('allowed-origin', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = trim((string) $input->getOption('repository'));
        $name = trim((string) $input->getOption('name'));
        $destination = trim((string) $input->getOption('destination'));
        if ($repository === '' || $name === '' || $destination === '') {
            $this->error($output, '--repository, --name and --destination are required.');
            return Command::INVALID;
        }

        try {
            $restore = $this->restorer->restore(
                $repository,
                $name,
                $destination,
                $this->optional($input->getOption('expected-head-sha')),
                $this->optional($input->getOption('expected-event')),
                $this->optional($input->getOption('expected-workflow-path')),
                $this->allowedOrigins($input->getOption('allowed-origin')),
            );
        } catch (\Throwable $exception) {
            $this->error($output, $exception->getMessage());
            return Command::INVALID;
        }

        $output->writeln((string) json_encode(
            $restore->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        return Command::SUCCESS;
    }

    /** @return list<ArtifactWorkflowOrigin> */
    private function allowedOrigins(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $origins = [];
        foreach ($value as $origin) {
            if (!is_string($origin) || !str_contains($origin, ':')) {
                throw new \InvalidArgumentException(
                    "--allowed-origin must use '<event>:<workflow-path>' format.",
                );
            }
            [$event, $workflowPath] = explode(':', $origin, 2);
            $event = trim($event);
            $workflowPath = trim($workflowPath);
            if ($event === '' || $workflowPath === '') {
                throw new \InvalidArgumentException(
                    "--allowed-origin must use '<event>:<workflow-path>' format.",
                );
            }
            $origins[] = new ArtifactWorkflowOrigin($event, $workflowPath);
        }

        return $origins;
    }

    private function optional(mixed $value): ?string
    {
        $value = trim(is_string($value) ? $value : '');
        return $value === '' ? null : $value;
    }

    private function error(OutputInterface $output, string $message): void
    {
        $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $error->writeln($message);
    }
}
