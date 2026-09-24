<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Release\ReleasePreflight;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:preflight', description: 'Validate a simple manual Nextcloud app release before creating the GitHub Release.')]
final class ReleasePreflightCommand extends Command
{
    public function __construct(private readonly ReleasePreflight $preflight)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('version', null, InputOption::VALUE_REQUIRED)
            ->addOption('stable-branch', null, InputOption::VALUE_REQUIRED)
            ->addOption('current-ref', null, InputOption::VALUE_REQUIRED)
            ->addOption('repository', null, InputOption::VALUE_REQUIRED)
            ->addOption('appinfo', null, InputOption::VALUE_REQUIRED, default: 'appinfo/info.xml')
            ->addOption('changelog', null, InputOption::VALUE_REQUIRED, default: 'CHANGELOG.md')
            ->addOption('milestone', null, InputOption::VALUE_REQUIRED, default: '')
            ->addOption('blocker-queries-json', null, InputOption::VALUE_REQUIRED, default: '[]')
            ->addOption('json', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $queries = json_decode((string) $input->getOption('blocker-queries-json'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($queries) || !array_is_list($queries)) {
                throw new \DomainException('blocker-queries-json must be a JSON array of strings');
            }
            foreach ($queries as $query) {
                if (!is_string($query)) {
                    throw new \DomainException('blocker-queries-json must be a JSON array of strings');
                }
            }

            $result = $this->preflight->check(
                trim((string) $input->getOption('version')),
                trim((string) $input->getOption('stable-branch')),
                trim((string) $input->getOption('current-ref')),
                $this->nullable((string) $input->getOption('repository')),
                (string) $input->getOption('appinfo'),
                (string) $input->getOption('changelog'),
                $this->nullable((string) $input->getOption('milestone')),
                $queries,
            );
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            foreach ($result['checks'] as $check) {
                $output->writeln(sprintf('%s %s: %s', $check['ok'] ? '[ok]' : '[fail]', $check['name'], $check['message']));
            }
        }

        return $result['ready'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
