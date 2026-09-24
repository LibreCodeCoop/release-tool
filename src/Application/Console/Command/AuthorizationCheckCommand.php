<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Security\RepositoryAuthorizationChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:authorization', description: 'Check whether a GitHub actor meets a repository permission threshold.')]
final class AuthorizationCheckCommand extends Command
{
    public function __construct(private readonly RepositoryAuthorizationChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'Repository in owner/name form.')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'GitHub actor login.')
            ->addOption('minimum', null, InputOption::VALUE_REQUIRED, 'Minimum repository permission.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = trim((string) $input->getOption('repository'));
        $actor = trim((string) $input->getOption('actor'));
        $minimum = trim((string) $input->getOption('minimum'));

        if ($repository === '' || $actor === '' || $minimum === '') {
            $output->writeln('<error>--repository, --actor and --minimum are required.</error>');
            return Command::INVALID;
        }

        try {
            $authorization = $this->checker->check($repository, $actor, $minimum);
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }

        $output->writeln((string) json_encode(
            $authorization->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return $authorization->authorized() ? Command::SUCCESS : 3;
    }
}
