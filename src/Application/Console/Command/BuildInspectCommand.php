<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Build\BuildMetadataInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'build:inspect', description: 'Read validated build metadata from a Nextcloud app checkout.')]
final class BuildInspectCommand extends Command
{
    public function __construct(private readonly BuildMetadataInspector $inspector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Application checkout path.', '.')
            ->addOption('github-output', null, InputOption::VALUE_REQUIRED, 'Append validated values to a GitHub Actions output file.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON metadata.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $metadata = $this->inspector->inspect((string) $input->getOption('root'));
            $destination = $input->getOption('github-output');
            if (is_string($destination) && $destination !== '') {
                $values = [
                    'version' => $metadata->version,
                    'nextcloud-min-version' => $metadata->nextcloudMinVersion,
                    'node-version' => $metadata->nodeVersion,
                    'npm-version' => $metadata->npmVersion,
                    'has-package-json' => $metadata->hasPackageJson ? 'true' : 'false',
                ];
                $lines = '';
                foreach ($values as $name => $value) {
                    $lines .= $name.'='.$value."\n";
                }
                if (@file_put_contents($destination, $lines, FILE_APPEND | LOCK_EX) === false) {
                    throw new \DomainException('Unable to write GitHub Actions output file.');
                }
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($metadata->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf(
                'Build metadata: %s (Nextcloud >= %s)',
                $metadata->version,
                $metadata->nextcloudMinVersion,
            ));
        }

        return Command::SUCCESS;
    }
}
