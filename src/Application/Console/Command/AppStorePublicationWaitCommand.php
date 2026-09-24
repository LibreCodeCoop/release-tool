<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Publication\AppStorePublicationWaiter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'publication:wait-appstore', description: 'Wait until a release is visible in the Nextcloud App Store API.')]
final class AppStorePublicationWaitCommand extends Command
{
    public function __construct(private readonly AppStorePublicationWaiter $waiter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('app-name', null, InputOption::VALUE_REQUIRED)
            ->addOption('version', null, InputOption::VALUE_REQUIRED)
            ->addOption('platform', null, InputOption::VALUE_REQUIRED)
            ->addOption('attempts', null, InputOption::VALUE_REQUIRED, default: '12')
            ->addOption('delay-seconds', null, InputOption::VALUE_REQUIRED, default: '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $app = trim((string) $input->getOption('app-name'));
            $version = ltrim(trim((string) $input->getOption('version')), 'v');
            $platform = $this->normalizePlatform((string) $input->getOption('platform'));
            $attempts = $this->integerOption($input, 'attempts');
            $delay = $this->integerOption($input, 'delay-seconds');
            if ($app === '' || $version === '') {
                throw new \DomainException('--app-name and --version are required.');
            }

            $apiUrl = sprintf('https://apps.nextcloud.com/api/v1/platform/%s/apps.json', $platform);
            $this->waiter->wait($apiUrl, $app, $version, $attempts, $delay);
            $output->writeln(sprintf('Verified %s %s in the Nextcloud App Store.', $app, $version));
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
    }

    private function normalizePlatform(string $platform): string
    {
        $parts = explode('.', trim($platform));
        if (count($parts) > 3) {
            throw new \DomainException(sprintf('Invalid Nextcloud platform version: %s', $platform));
        }
        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part)) {
                throw new \DomainException(sprintf('Invalid Nextcloud platform version: %s', $platform));
            }
        }

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts);
    }

    private function integerOption(InputInterface $input, string $name): int
    {
        $value = trim((string) $input->getOption($name));
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \DomainException(sprintf('%s must be an integer.', $name));
        }
        return (int) $value;
    }
}
