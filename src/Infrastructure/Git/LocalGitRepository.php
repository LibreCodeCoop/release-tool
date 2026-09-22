<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Git;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\CommitInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Domain\Version\Version;
use Symfony\Component\Process\Process;

final readonly class LocalGitRepository implements GitRepository
{
    public function __construct(private string $root)
    {
    }

    public function repositoryIdentity(): ?string
    {
        $remote = trim($this->run(['git', 'config', '--get', 'remote.origin.url'], allowFailure: true));
        if ($remote === '') {
            return null;
        }

        foreach ([
            '#^https://github\.com/(?<repo>[^/]+/[^/]+?)(?:\.git)?$#',
            '#^git@github\.com:(?<repo>[^/]+/[^/]+?)(?:\.git)?$#',
            '#^ssh://git@github\.com/(?<repo>[^/]+/[^/]+?)(?:\.git)?$#',
        ] as $pattern) {
            if (preg_match($pattern, $remote, $match) === 1) {
                return preg_replace('/\\.git$/', '', $match['repo']) ?: $match['repo'];
            }
        }

        return null;
    }

    public function resolve(string $ref): string
    {
        $sha = trim($this->run(['git', 'rev-parse', '--verify', $ref . '^{commit}']));
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new DomainException(sprintf('Git ref did not resolve to an immutable commit: %s', $ref));
        }

        return $sha;
    }

    public function branchHead(string $branch): string
    {
        foreach (['refs/remotes/origin/' . $branch, 'refs/heads/' . $branch, $branch] as $ref) {
            try {
                return $this->resolve($ref);
            } catch (DomainException) {
                // Try the next deterministic branch ref.
            }
        }

        throw new DomainException(sprintf('Branch does not exist locally: %s', $branch));
    }

    public function isAncestor(string $ancestorSha, string $descendantSha): bool
    {
        $process = new Process(['git', 'merge-base', '--is-ancestor', $ancestorSha, $descendantSha], $this->root);
        $process->run();

        if ($process->getExitCode() === 0) {
            return true;
        }
        if ($process->getExitCode() === 1) {
            return false;
        }

        throw new DomainException(trim($process->getErrorOutput()) ?: 'Unable to evaluate Git ancestry.');
    }

    public function tagExists(string $tag): bool
    {
        $process = new Process(['git', 'show-ref', '--verify', '--quiet', 'refs/tags/' . $tag], $this->root);
        $process->run();

        return $process->getExitCode() === 0;
    }

    public function readFile(string $sha, string $path): string
    {
        return $this->run(['git', 'show', $sha . ':' . $path]);
    }

    public function commitDate(string $sha): string
    {
        $date = trim($this->run(['git', 'show', '-s', '--format=%cs', $sha]));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) !== 1) {
            throw new DomainException(sprintf('Git commit date is invalid for %s.', $sha));
        }

        return $date;
    }

    public function previousRelease(
        string $baseSha,
        string $tagPrefix,
        ?string $initialRef,
        ?string $excludeTag = null,
    ): PreviousRelease {
        $tags = preg_split('/\R/', trim($this->run(['git', 'tag', '--merged', $baseSha, '--list', $tagPrefix . '*'])));
        $candidates = [];

        foreach ($tags ?: [] as $tag) {
            if ($tag === '' || $tag === $excludeTag || !str_starts_with($tag, $tagPrefix)) {
                continue;
            }

            try {
                $version = Version::parse(substr($tag, strlen($tagPrefix)));
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($version->development) {
                continue;
            }

            $sha = $this->resolve($tag);
            $distance = (int) trim($this->run(['git', 'rev-list', '--count', $sha . '..' . $baseSha]));
            $candidates[] = [
                'tag' => $tag,
                'version' => $version,
                'sha' => $sha,
                'distance' => $distance,
            ];
        }

        if ($candidates !== []) {
            usort($candidates, static function (array $left, array $right): int {
                $distance = $left['distance'] <=> $right['distance'];
                if ($distance !== 0) {
                    return $distance;
                }

                return $right['version']->compare($left['version']);
            });
            $selected = $candidates[0];

            return new PreviousRelease($selected['tag'], $selected['sha'], $selected['tag']);
        }

        if ($initialRef === null) {
            throw new DomainException('No reachable release tag found and no history.initial_ref is configured.');
        }

        $sha = $this->resolve($initialRef);
        if (!$this->isAncestor($sha, $baseSha)) {
            throw new DomainException('Configured history.initial_ref is not an ancestor of the planning base.');
        }

        return new PreviousRelease(null, $sha, $initialRef);
    }

    public function commitsBetween(string $fromSha, string $toSha): array
    {
        $raw = trim($this->run(['git', 'rev-list', '--reverse', $fromSha . '..' . $toSha]));
        if ($raw === '') {
            return [];
        }

        $commits = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $sha) {
            $subject = trim($this->run(['git', 'show', '-s', '--format=%s', $sha]));
            $pathsRaw = trim($this->run(['git', 'diff-tree', '--no-commit-id', '--name-only', '-r', $sha]));
            $paths = $pathsRaw === '' ? [] : array_values(array_filter(preg_split('/\R/', $pathsRaw) ?: []));

            $commits[] = new CommitInfo($sha, $subject, $paths);
        }

        return $commits;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, bool $allowFailure = false): string
    {
        $process = new Process($command, $this->root);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful() && !$allowFailure) {
            throw new DomainException(
                trim($process->getErrorOutput()) ?: sprintf('Command failed: %s', implode(' ', $command)),
            );
        }

        return $process->getOutput();
    }
}
