<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\PreparedReleaseCodec;
use LibreCode\ReleaseTool\Application\Release\ReleaseFinalizer;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparationCodec;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:finalize', description: 'Revalidate a merged release preparation into PreparedRelease v1.')]
final class ReleaseFinalizeCommand extends Command
{
    public function __construct(
        private readonly ReleaseFinalizer $finalizer,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
        private readonly ReleasePreparationCodec $preparationCodec = new ReleasePreparationCodec(),
        private readonly PreparedReleaseCodec $preparedCodec = new PreparedReleaseCodec(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('preparation', null, InputOption::VALUE_REQUIRED, 'ReleasePreparation v1 JSON path.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('apply-history-sync', null, InputOption::VALUE_NONE, 'Create or reuse the changelog-only history synchronization pull request.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit PreparedRelease v1 JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $preparationPath = $input->getOption('preparation');
        if (!is_string($preparationPath) || trim($preparationPath) === '') {
            return $this->error($output, $input, '--preparation is required.');
        }

        try {
            $json = file_get_contents($preparationPath);
            if ($json === false) {
                throw new \RuntimeException(sprintf('Unable to read ReleasePreparation: %s', $preparationPath));
            }
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $prepared = $this->finalizer->finalize(
                $config,
                $this->preparationCodec->decode($json),
                (bool) $input->getOption('apply-history-sync'),
            );
        } catch (\Throwable $exception) {
            return $this->error($output, $input, $exception->getMessage());
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($this->preparedCodec->encode($prepared));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('PreparedRelease: %s', $prepared->id));
        $output->writeln(sprintf('Version: %s', $prepared->version));
        $output->writeln(sprintf('Final SHA: %s', $prepared->finalSha));
        $output->writeln(sprintf('History sync: %s', $prepared->historySynchronization->state->value));
        if ($prepared->historySynchronization->pullRequestUrl !== null) {
            $output->writeln(sprintf('History sync PR: %s', $prepared->historySynchronization->pullRequestUrl));
        }

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
