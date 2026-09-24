<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Release\StableBranchSelector;
use LibreCode\ReleaseTool\Application\Console\Port\ActionEnvironment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:stable-select', description: 'Resolve whether a branch is the latest exact stable<N> release branch.')]
final class StableSelectCommand extends Command
{
    public function __construct(
        private readonly StableBranchSelector $selector,
        private readonly ActionEnvironment $actions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'Repository in owner/name form.')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Branch to compare.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = trim((string) ($input->getOption('repository') ?? ''));
        if ($repository === '') {
            $repository = trim((string) (getenv('GITHUB_REPOSITORY') ?: ''));
        }

        $branch = trim((string) ($input->getOption('branch') ?? ''));
        if ($branch === '') {
            $branch = trim((string) (getenv('GITHUB_REF_NAME') ?: ''));
        }

        if ($repository === '') {
            $output->writeln('<error>Repository is required.</error>');
            return Command::INVALID;
        }

        try {
            $state = $this->selector->select($repository, $branch);
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $currentMajor = $state->currentMajor === null ? 'n/a' : (string) $state->currentMajor;
        $latestBranch = $state->latestBranch ?? 'none';
        $latestMajor = $state->latestMajor === null ? 'n/a' : (string) $state->latestMajor;
        $isLatest = $state->isLatest() ? 'true' : 'false';

        $output->writeln('Current branch: ' . $state->currentBranch);
        $output->writeln('Current release line: ' . $currentMajor);
        $output->writeln('Latest stable branch: ' . $latestBranch);
        $output->writeln('Latest release line: ' . $latestMajor);
        $output->writeln('Publish nightly: ' . $isLatest);

        $this->actions->output('current_branch', $state->currentBranch);
        $this->actions->output('current_major', $state->currentMajor === null ? '' : (string) $state->currentMajor);
        $this->actions->output('latest_branch', $state->latestBranch ?? '');
        $this->actions->output('latest_major', $state->latestMajor === null ? '' : (string) $state->latestMajor);
        $this->actions->output('is_latest', $isLatest);
        $this->actions->summary(
            "### Stable release branch selection\n\n"
            . sprintf("- Current branch: \x60%s\x60\n", $state->currentBranch)
            . sprintf("- Current release line: \x60%s\x60\n", $currentMajor)
            . sprintf("- Latest stable branch: \x60%s\x60\n", $latestBranch)
            . sprintf("- Latest release line: \x60%s\x60\n", $latestMajor)
            . sprintf("- Publish nightly: \x60%s\x60\n", $isLatest),
        );

        return Command::SUCCESS;
    }
}
