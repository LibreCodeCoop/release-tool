<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Git;

use DomainException;
use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Release\Port\StableBranchSource;
use Symfony\Component\Process\Process;

final readonly class RemoteStableBranchSource implements StableBranchSource
{
    public function stableBranches(string $repository): array
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid repository: %s', $repository));
        }

        $process = new Process([
            'git',
            'ls-remote',
            '--heads',
            'https://github.com/' . $repository . '.git',
            'refs/heads/stable*',
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new DomainException(trim($process->getErrorOutput()) ?: 'Unable to list remote stable branches.');
        }

        $branches = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }
            if (preg_match('#^refs/heads/(stable[1-9][0-9]*)$#', $parts[1], $matches) !== 1) {
                continue;
            }
            $branches[] = $matches[1];
        }

        return $branches;
    }
}
