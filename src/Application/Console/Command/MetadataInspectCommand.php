<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\LocalReleaseMetadataInspector;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metadata:inspect', description: 'Validate and inspect release metadata for a consumer repository.')]
final class MetadataInspectCommand extends Command
{
    public function __construct(
        private readonly GitRepository $git,
        private readonly LocalReleaseMetadataInspector $inspector,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Git ref to inspect.', 'HEAD')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $sha = $this->git->resolve((string) $input->getOption('ref'));
            $metadata = $this->inspector->inspect(
                $config,
                $sha,
                $this->git->readFile(
                    $sha,
                    str_replace('{major}', (string) $this->inspector->inspect(
                        $config,
                        $sha,
                        $this->git->readFile($sha, str_replace('{major}', '0', $config->changelogPath)),
                    )->major,
                    $config->changelogPath,
                ),
            );
        } catch (\Throwable $exception) {
            if ((bool) $input->getOption('json')) {
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

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['valid' => true] + $metadata->jsonSerialize(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Version: %s', $metadata->version));
        $output->writeln(sprintf('Major: %d', $metadata->major));
        $output->writeln(sprintf('Changelog: %s', $metadata->changelogPath));
        $output->writeln(sprintf('Development: %s', $metadata->development ? 'yes' : 'no'));
        return Command::SUCCESS;
    }
}
