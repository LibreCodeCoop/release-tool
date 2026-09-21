<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Artifact\ArtifactValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'artifact:validate', description: 'Validate a built release archive against the consumer release contract.')]
final class ArtifactValidateCommand extends Command
{
    public function __construct(
        private readonly ArtifactValidator $validator,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Built release archive path.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('expected-app-id', null, InputOption::VALUE_REQUIRED, 'Expected packaged app id; defaults to consumer configuration.')
            ->addOption('expected-version', null, InputOption::VALUE_REQUIRED, 'Expected release version.')
            ->addOption('expected-digest', null, InputOption::VALUE_REQUIRED, 'Optional expected SHA-256 digest.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>--format must be text or json.</error>');
            return Command::INVALID;
        }

        $expectedVersion = trim((string) $input->getOption('expected-version'));
        if ($expectedVersion === '') {
            $output->writeln('<error>--expected-version is required.</error>');
            return Command::INVALID;
        }

        try {
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $expectedAppId = trim((string) ($input->getOption('expected-app-id') ?? ''));
            if ($expectedAppId === '') {
                $expectedAppId = $config->appId;
            }

            $validation = $this->validator->validate(
                (string) $input->getArgument('archive'),
                $config,
                $expectedAppId,
                $expectedVersion,
                ($input->getOption('expected-digest') !== null)
                    ? (string) $input->getOption('expected-digest')
                    : null,
            );
        } catch (\Throwable $exception) {
            if ($format === 'json') {
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

        if ($format === 'json') {
            $output->writeln((string) json_encode(
                $validation,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));
        } else {
            $output->writeln(sprintf('Artifact: %s', $validation->artifactName));
            $output->writeln(sprintf('SHA-256: %s', $validation->sha256));
            $output->writeln(sprintf(
                'App: %s (expected %s)',
                $validation->actualAppId ?? 'unknown',
                $validation->expectedAppId,
            ));
            $output->writeln(sprintf(
                'Version: %s (expected %s)',
                $validation->actualVersion ?? 'unknown',
                $validation->expectedVersion,
            ));
            foreach ($validation->errors as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }
            $output->writeln($validation->valid ? '<info>Artifact is valid.</info>' : '<error>Artifact is invalid.</error>');
        }

        return $validation->valid ? Command::SUCCESS : Command::FAILURE;
    }
}
