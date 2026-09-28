<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\ReleaseIdentityValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:validate-identity', description: 'Validate a release tag against consumer metadata before publication.')]
final class ReleaseValidateIdentityCommand extends Command
{
    public function __construct(
        private readonly ReleaseIdentityValidator $validator,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Release tag to validate.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Git ref whose app metadata must match the tag.', 'HEAD')
            ->addOption('require-tag-exists', null, InputOption::VALUE_NONE, 'Require the release tag to already exist locally.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $root = (string) $input->getOption('root');
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, $root);

            $result = $this->validator->validate(
                $config,
                (string) $input->getOption('tag'),
                (string) $input->getOption('ref'),
                (bool) $input->getOption('require-tag-exists'),
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
                ['schema' => 1, 'valid' => true] + $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Release identity valid: tag=%s version=%s', $result['tag'], $result['version']));

        return Command::SUCCESS;
    }
}
