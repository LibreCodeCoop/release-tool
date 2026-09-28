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
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Git ref whose app metadata must match the tag.', 'HEAD')
            ->addOption('require-tag-exists', null, InputOption::VALUE_REQUIRED, 'Require the release tag to already exist locally.', 'false')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.')
            ->addOption('github-output', null, InputOption::VALUE_REQUIRED, 'Write GitHub Actions outputs to this file.')
            ->addOption('github-step-summary', null, InputOption::VALUE_REQUIRED, 'Write a GitHub Actions step summary to this file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, '.');

            $requireTagExists = filter_var(
                (string) $input->getOption('require-tag-exists'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            if ($requireTagExists === null) {
                throw new \DomainException("require-tag-exists must be either 'true' or 'false'.");
            }

            $result = $this->validator->validate(
                $config,
                (string) $input->getOption('tag'),
                (string) $input->getOption('ref'),
                $requireTagExists,
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

        $githubOutput = trim((string) $input->getOption('github-output'));
        if ($githubOutput !== '') {
            file_put_contents($githubOutput, sprintf(
                "tag=%s\nversion=%s\nsha=%s\n",
                $result['tag'],
                $result['version'],
                $result['sha'],
            ), FILE_APPEND | LOCK_EX);
        }

        $githubStepSummary = trim((string) $input->getOption('github-step-summary'));
        if ($githubStepSummary !== '') {
            file_put_contents($githubStepSummary, sprintf(
                "## Release identity\n\n- Tag: `%s`\n- App version: `%s`\n- Metadata SHA: `%s`\n- Result: **valid**\n",
                $result['tag'],
                $result['version'],
                $result['sha'],
            ), FILE_APPEND | LOCK_EX);
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
