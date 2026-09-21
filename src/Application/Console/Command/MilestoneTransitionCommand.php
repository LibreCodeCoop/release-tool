<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\MilestoneTransitionCodec;
use LibreCode\ReleaseTool\Application\Release\MilestoneTransitioner;
use LibreCode\ReleaseTool\Application\Release\PreparedReleaseCodec;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'milestone:transition', description: 'Plan or apply MilestoneTransition v1 from PreparedRelease v1.')]
final class MilestoneTransitionCommand extends Command
{
    public function __construct(
        private readonly MilestoneTransitioner $transitioner,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
        private readonly PreparedReleaseCodec $preparedCodec = new PreparedReleaseCodec(),
        private readonly MilestoneTransitionCodec $transitionCodec = new MilestoneTransitionCodec(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('prepared', null, InputOption::VALUE_REQUIRED, 'PreparedRelease v1 JSON path.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('create-follow-up', null, InputOption::VALUE_NONE, 'Create the next configured milestone and move remaining open items.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the exact planned milestone operations.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $preparedPath = $input->getOption('prepared');
        if (!is_string($preparedPath) || trim($preparedPath) === '') {
            return $this->error($output, $input, '--prepared is required.');
        }

        try {
            $json = file_get_contents($preparedPath);
            if ($json === false) {
                throw new \RuntimeException(sprintf('Unable to read PreparedRelease: %s', $preparedPath));
            }
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $prepared = $this->preparedCodec->decode($json);
            $plan = $this->transitioner->plan(
                $config,
                $prepared,
                (bool) $input->getOption('create-follow-up'),
            );
            if (!(bool) $input->getOption('apply')) {
                $output->writeln((string) json_encode(
                    $plan,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                ));
                return Command::SUCCESS;
            }
            $transition = $this->transitioner->apply($plan);
        } catch (\Throwable $exception) {
            return $this->error($output, $input, $exception->getMessage());
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($this->transitionCodec->encode($transition));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Milestone: %s', $transition->finalTitle));
        $output->writeln(sprintf('Released milestone: %s', $transition->releasedMilestoneUrl));
        if ($transition->followUpMilestoneUrl !== null) {
            $output->writeln(sprintf('Follow-up milestone: %s', $transition->followUpMilestoneUrl));
        }
        $output->writeln(sprintf(
            'Moved: %d issue(s), %d pull request(s)',
            $transition->movedIssues,
            $transition->movedPullRequests,
        ));
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
