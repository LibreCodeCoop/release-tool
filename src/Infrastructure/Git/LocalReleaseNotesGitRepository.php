<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Git;

use LibreCode\ReleaseTool\Application\ReleaseNotes\Port\ReleaseNotesGitRepository;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class LocalReleaseNotesGitRepository implements ReleaseNotesGitRepository
{
    public function __construct(private string $workingDirectory)
    {
    }

    public function commits(string $fromRef, string $toRef, int $fallbackLimit): array
    {
        $arguments = ['git', 'rev-list', '--reverse'];
        if ($fromRef !== '') {
            $arguments[] = $fromRef . '..' . $toRef;
        } else {
            $arguments[] = '--max-count=' . $fallbackLimit;
            $arguments[] = $toRef;
        }

        return $this->lines($arguments);
    }

    public function subject(string $sha): string
    {
        $lines = $this->lines(['git', 'show', '-s', '--format=%s', $sha]);
        if ($lines === []) {
            throw new RuntimeException(sprintf('cannot resolve subject for commit %s', $sha));
        }

        return $lines[0];
    }

    /** @param list<string> $arguments @return list<string> */
    private function lines(array $arguments): array
    {
        $process = new Process($arguments, $this->workingDirectory);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                trim($process->getErrorOutput()) ?: sprintf('Command failed: %s', implode(' ', $arguments)),
            );
        }

        $lines = preg_split('/\R/', $process->getOutput());
        if (!is_array($lines)) {
            return [];
        }

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}
