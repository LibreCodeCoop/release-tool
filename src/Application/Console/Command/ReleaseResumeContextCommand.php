<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Release\ReleaseResumeContextResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:resume-context', description: 'Validate a merged release preparation PR and resolve its resume context.')]
final class ReleaseResumeContextCommand extends Command
{
    public function __construct(private readonly ReleaseResumeContextResolver $resolver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'GitHub repository in owner/name form.')
            ->addOption('pull-request-number', null, InputOption::VALUE_REQUIRED, 'Merged release preparation PR number.')
            ->addOption('github-output', null, InputOption::VALUE_REQUIRED, 'Optional GitHub Actions output file path.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = trim((string) $input->getOption('repository'));
        $number = filter_var($input->getOption('pull-request-number'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($repository === '' || !is_int($number)) {
            return $this->error($output, $input, '--repository and a positive --pull-request-number are required.');
        }

        try {
            $context = $this->resolver->resolve($repository, $number);
        } catch (\Throwable $exception) {
            return $this->error($output, $input, $exception->getMessage());
        }

        $githubOutput = $input->getOption('github-output');
        if (is_string($githubOutput) && $githubOutput !== '') {
            if (file_put_contents(
                $githubOutput,
                sprintf("base-ref=%s\nmerger=%s\n", $context->baseBranch, $context->merger),
                FILE_APPEND,
            ) === false) {
                return $this->error($output, $input, sprintf('Unable to write GitHub output file: %s', $githubOutput));
            }
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'pull_request_number' => $context->pullRequestNumber,
                'base_ref' => $context->baseBranch,
                'merger' => $context->merger,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Pull request: #%d', $context->pullRequestNumber));
        $output->writeln(sprintf('Base branch: %s', $context->baseBranch));
        $output->writeln(sprintf('Merger: %s', $context->merger));

        return Command::SUCCESS;
    }

    private function error(OutputInterface $output, InputInterface $input, string $message): int
    {
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['schema' => 1, 'error' => $message], JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }
        return Command::INVALID;
    }
}
