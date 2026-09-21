<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Publication\PublicationVerificationCodec;
use LibreCode\ReleaseTool\Application\Publication\PublicationVerifier;
use LibreCode\ReleaseTool\Application\Release\PreparedReleaseCodec;
use LibreCode\ReleaseTool\Application\Release\ReleaseDraftCodec;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'publication:verify', description: 'Verify a published release, publisher run, artifact and App Store visibility.')]
final class PublicationVerifyCommand extends Command
{
    public function __construct(
        private readonly PublicationVerifier $verifier,
        private readonly ConsumerConfigContextValidator $contextValidator,
        private readonly ConsumerConfigLoader $configLoader = new ConsumerConfigLoader(),
        private readonly ReleaseDraftCodec $draftCodec = new ReleaseDraftCodec(),
        private readonly PreparedReleaseCodec $preparedCodec = new PreparedReleaseCodec(),
        private readonly PublicationVerificationCodec $verificationCodec = new PublicationVerificationCodec(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('draft', null, InputOption::VALUE_REQUIRED, 'ReleaseDraft v1 JSON path.')
            ->addOption('prepared', null, InputOption::VALUE_REQUIRED, 'PreparedRelease v1 JSON path.')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Consumer configuration path.', '.nextcloud-release.yml')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Consumer repository root.', '.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>--format must be text or json.</error>');
            return Command::INVALID;
        }

        try {
            $draftPath = $this->requiredPath($input, 'draft');
            $preparedPath = $this->requiredPath($input, 'prepared');
            $config = $this->configLoader->load((string) $input->getOption('config'));
            $this->contextValidator->validate($config, (string) $input->getOption('root'));
            $draft = $this->draftCodec->decode((string) file_get_contents($draftPath));
            $prepared = $this->preparedCodec->decode((string) file_get_contents($preparedPath));
            $verification = $this->verifier->verify($config, $draft, $prepared);
        } catch (\Throwable $exception) {
            if ($format === 'json') {
                $output->writeln((string) json_encode([
                    'schema' => 1,
                    'success' => false,
                    'error' => $exception->getMessage(),
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');
            }
            return Command::INVALID;
        }

        if ($format === 'json') {
            $output->writeln($this->verificationCodec->encode($verification));
        } else {
            $output->writeln(sprintf('Release: %s @ %s', $verification->tagName, $verification->targetSha));
            $output->writeln(sprintf('Publisher: %s', $verification->publisherSucceeded ? 'success' : 'not successful'));
            $output->writeln(sprintf('Artifact: %s', $verification->artifactValid ? 'valid' : 'invalid'));
            $output->writeln(sprintf('App Store: %s', $verification->appStoreVisible ? 'visible' : 'not visible'));
            foreach ($verification->errors as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }
        }

        return $verification->success ? Command::SUCCESS : Command::FAILURE;
    }

    private function requiredPath(InputInterface $input, string $option): string
    {
        $path = trim((string) $input->getOption($option));
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('--%s must point to a readable file.', $option));
        }
        return $path;
    }
}
