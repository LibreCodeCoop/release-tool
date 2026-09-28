<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Release\PlanReleaseInput;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanReporter;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:plan', description: 'Build a read-only ReleasePlan v1 for a release line.')]
final class ReleasePlanCommand extends Command
{
    public const int EXIT_NOT_READY = 3;

    public function __construct(
        private readonly ReleasePlanning $planner,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
        private readonly ConsumerConfigContextValidator $contextValidator = new NoopConsumerConfigContextValidator(),
        private readonly ReleasePlanReporter $reporter = new ReleasePlanReporter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Release branch to plan.')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Immutable or resolvable planning ref.')
            ->addOption('release-version', null, InputOption::VALUE_REQUIRED, 'Explicit release version override.')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Release channel: alpha, beta, rc or final.', 'final')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Release mode: normal or security.', 'normal')
            ->addOption('safe-public-text', null, InputOption::VALUE_REQUIRED, 'Explicitly public-safe release text.')
            ->addOption('ignore-open-backport', null, InputOption::VALUE_NONE, 'Explicitly override open backport blockers.')
            ->addOption('create-follow-up-milestone', null, InputOption::VALUE_NONE, 'Request explicit follow-up milestone creation downstream.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit ReleasePlan v1 JSON.')
            ->addOption('output-file', null, InputOption::VALUE_REQUIRED, 'Write ReleasePlan v1 JSON to this file.')
            ->addOption('github-output', null, InputOption::VALUE_REQUIRED, 'Write GitHub Actions outputs to this file.')
            ->addOption('github-step-summary', null, InputOption::VALUE_REQUIRED, 'Write a GitHub Actions step summary to this file.')
            ->addOption('github-annotations', null, InputOption::VALUE_NONE, 'Emit GitHub Actions annotations for a plan that is not ready.')
            ->addOption('tool-version', null, InputOption::VALUE_REQUIRED, 'Release-tool version used in CI reporting.', 'unknown');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $branch = $input->getOption('branch');
        if (!is_string($branch) || trim($branch) === '') {
            return $this->error($output, $input, '--branch is required.');
        }

        $channel = ReleaseChannel::tryFrom((string) $input->getOption('channel'));
        if ($channel === null) {
            return $this->error($output, $input, 'Invalid --channel; expected alpha, beta, rc or final.');
        }

        $mode = ReleaseMode::tryFrom((string) $input->getOption('mode'));
        if ($mode === null) {
            return $this->error($output, $input, 'Invalid --mode; expected normal or security.');
        }

        try {
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $plan = $this->planner->plan(
                $config,
                new PlanReleaseInput(
                    trim($branch),
                    $this->nullableString($input->getOption('ref')),
                    $this->nullableString($input->getOption('release-version')),
                    $channel,
                    (bool) $input->getOption('ignore-open-backport'),
                    (bool) $input->getOption('create-follow-up-milestone'),
                    $mode,
                    $this->nullableString($input->getOption('safe-public-text')),
                ),
            );
        } catch (\Throwable $exception) {
            return $this->error($output, $input, $exception->getMessage());
        }

        $json = (string) json_encode(
            $plan,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        $outputFile = trim((string) $input->getOption('output-file'));
        if ($outputFile !== '') {
            file_put_contents($outputFile, $json . "\n", LOCK_EX);
        }

        $githubOutput = trim((string) $input->getOption('github-output'));
        if ($githubOutput !== '') {
            file_put_contents(
                $githubOutput,
                sprintf("ready=%s\nplan-path=%s\n", $plan->ready ? 'true' : 'false', $outputFile),
                FILE_APPEND | LOCK_EX,
            );
        }

        $githubStepSummary = trim((string) $input->getOption('github-step-summary'));
        if ($githubStepSummary !== '') {
            file_put_contents(
                $githubStepSummary,
                $this->reporter->summary($plan, (string) $input->getOption('tool-version')),
                FILE_APPEND | LOCK_EX,
            );
        }

        if ((bool) $input->getOption('github-annotations')) {
            foreach ($this->reporter->githubAnnotations($plan) as $annotation) {
                $output->writeln($annotation);
            }
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($json);
        } elseif ($outputFile === '') {
            $this->renderHuman($output, $plan);
        }

        return $plan->ready ? Command::SUCCESS : self::EXIT_NOT_READY;
    }

    private function renderJson(OutputInterface $output, ReleasePlan $plan): void
    {
        $output->writeln((string) json_encode(
            $plan,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ));
    }

    private function renderHuman(OutputInterface $output, ReleasePlan $plan): void
    {
        $output->writeln(sprintf('Release plan: %s', $plan->id));
        $output->writeln(sprintf('Repository: %s', $plan->repository));
        $output->writeln(sprintf('Branch: %s @ %s', $plan->branch, $plan->planningBaseSha));
        $output->writeln(sprintf('Version: %s -> %s (%s)', $plan->currentVersion, $plan->proposedVersion, $plan->channel->value));
        $output->writeln(sprintf('Changelog: %s', $plan->changelogTarget));
        $output->writeln(sprintf('Ready: %s', $plan->ready ? 'yes' : 'no'));

        foreach ($plan->warnings as $warning) {
            $output->writeln('Warning: ' . $warning);
        }
    }

    private function error(OutputInterface $output, InputInterface $input, string $message): int
    {
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'error' => $message,
            ], JSON_UNESCAPED_SLASHES));

            return Command::INVALID;
        }

        $output->writeln('<error>' . $message . '</error>');

        return Command::INVALID;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
