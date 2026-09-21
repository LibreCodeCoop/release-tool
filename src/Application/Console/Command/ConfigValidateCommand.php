<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:validate', description: 'Validate a release consumer configuration.')]
final class ConfigValidateCommand extends Command
{
    public function __construct(
        private readonly ConsumerConfigLoader $loader = new ConsumerConfigLoader(),
        private readonly ConsumerConfigContextValidator $contextValidator,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('config', null, InputOption::VALUE_REQUIRED, 'Configuration file path.', '.nextcloud-release.yml');
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit versioned machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getOption('config');

        try {
            $config = $this->loader->load($path);
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
        } catch (\Throwable $exception) {
            if ($input->getOption('json')) {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'valid' => false,
                    'error' => $exception->getMessage(),
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');
            }

            return Command::INVALID;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'valid' => true,
                'app_id' => $config->appId,
                'repository' => $config->repository,
            ], JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Valid schema v%d configuration for %s.</info>', $config->schema, $config->appId));

        return Command::SUCCESS;
    }
}
