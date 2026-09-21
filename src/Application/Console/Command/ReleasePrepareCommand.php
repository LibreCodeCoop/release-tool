<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanCodec;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparationCodec;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparationPublishing;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:prepare', description: 'Prepare a deterministic ReleasePreparation v1 from a ReleasePlan v1.')]
final class ReleasePrepareCommand extends Command
{
    public function __construct(
        private readonly ReleasePreparer $preparer,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ?ReleasePreparationPublishing $publisher = null,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
        private readonly ReleasePlanCodec $planCodec = new ReleasePlanCodec(),
        private readonly ReleasePreparationCodec $preparationCodec = new ReleasePreparationCodec(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'ReleasePlan v1 JSON path.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Create or reuse the generated release pull request.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit ReleasePreparation v1 JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $planPath = $input->getOption('plan');
        if (!is_string($planPath) || trim($planPath) === '') {
            return $this->error($output, $input, '--plan is required.');
        }

        try {
            $json = file_get_contents($planPath);
            if ($json === false) {
                throw new \RuntimeException(sprintf('Unable to read ReleasePlan: %s', $planPath));
            }

            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $plan = $this->planCodec->decode($json);
            $result = $this->preparer->prepare($config, $plan);
            $preparation = $result->preparation;

            if ((bool) $input->getOption('apply')) {
                if ($this->publisher === null) {
                    throw new \RuntimeException('Release preparation apply adapter is unavailable.');
                }
                $preparation = $this->publisher->publish($preparation);
            }
        } catch (\Throwable $exception) {
            return $this->error($output, $input, $exception->getMessage());
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($this->preparationCodec->encode($preparation));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('ReleasePreparation: %s', $preparation->id));
        $output->writeln(sprintf('ReleasePlan: %s', $preparation->releasePlanId));
        $output->writeln(sprintf('Target: %s @ %s', $preparation->targetBranch, $preparation->planningBaseSha));
        $output->writeln(sprintf('Version: %s (%s)', $preparation->version, $preparation->channel->value));
        $output->writeln(sprintf('Generated branch: %s', $preparation->generatedBranch));
        if ($preparation->pullRequestUrl !== null) {
            $output->writeln(sprintf('Pull request: %s', $preparation->pullRequestUrl));
        } else {
            $output->writeln('Pull request: dry-run only');
        }
        $output->writeln('');
        $output->writeln($result->diff);

        return Command::SUCCESS;
    }

    private function error(OutputInterface $output, InputInterface $input, string $message): int
    {
        if ((bool) $input->getOption('json')) {
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
