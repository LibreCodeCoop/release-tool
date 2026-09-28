<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
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
        private readonly ReleasePlanOutputPublisher $publisher = new ReleasePlanOutputPublisher(),
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
        try {
            $request = ReleasePlanCommandRequest::fromInput($input);
            $config = $this->configLoader->load($request->configPath);
            $this->contextValidator->validate($config, $request->root);
            $plan = $this->planner->plan($config, $request->planInput);
            $this->publisher->publish($plan, $request->output, $output);
        } catch (\Throwable $exception) {
            return $this->error($output, $input, $exception->getMessage());
        }

        return $plan->ready ? Command::SUCCESS : self::EXIT_NOT_READY;
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
}
