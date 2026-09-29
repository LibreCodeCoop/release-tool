<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Release\ReleasePipelinePinVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:pipeline-verify', description: 'Verify immutable release pipeline pins against a trusted reference.')]
final class ReleasePipelineVerifyCommand extends Command
{
    public function __construct(private readonly ReleasePipelinePinVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('candidate-ref', null, InputOption::VALUE_REQUIRED, default: 'HEAD')
            ->addOption('reference-ref', null, InputOption::VALUE_REQUIRED)
            ->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('action-prefix', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('json', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $referenceRef = trim((string) $input->getOption('reference-ref'));
        $candidateRef = trim((string) $input->getOption('candidate-ref'));
        $paths = $this->strings($input->getOption('file'));
        $prefixes = $this->strings($input->getOption('action-prefix'));

        if ($candidateRef === '' || $referenceRef === '' || $paths === [] || $prefixes === []) {
            $output->writeln('<error>candidate-ref, reference-ref, file and action-prefix are required.</error>');
            return Command::INVALID;
        }

        try {
            $mismatches = $this->verifier->mismatches($candidateRef, $referenceRef, $paths, $prefixes);
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'schema' => 1,
                'aligned' => $mismatches === [],
                'mismatches' => $mismatches,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } elseif ($mismatches !== []) {
            foreach ($mismatches as $path => $pins) {
                $output->writeln(sprintf('<error>Release pipeline pins differ: %s</error>', $path));
                $output->writeln('  reference: ' . implode(', ', $pins['reference']));
                $output->writeln('  candidate: ' . implode(', ', $pins['candidate']));
            }
        }

        return $mismatches === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
