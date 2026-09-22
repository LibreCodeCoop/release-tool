<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\CommitInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;

final class InMemoryGitRepository implements GitRepository
{
    /**
     * @param array<string, string> $refs
     * @param array<string, list<string>> $ancestors
     * @param array<string, string> $files
     * @param list<CommitInfo> $commits
     * @param list<string> $tags
     */
    public function __construct(
        private readonly string $repository,
        private readonly array $refs,
        private readonly array $ancestors,
        private readonly PreviousRelease $previous,
        private readonly array $files,
        private readonly array $commits = [],
        private readonly array $tags = [],
        private readonly string $date = '2026-09-21',
    ) {
    }

    public function repositoryIdentity(): ?string
    {
        return $this->repository;
    }

    public function resolve(string $ref): string
    {
        if (isset($this->refs[$ref])) {
            return $this->refs[$ref];
        }
        if (preg_match('/^[0-9a-f]{40}$/', $ref) === 1) {
            return $ref;
        }

        throw new DomainException('Unknown fake ref: ' . $ref);
    }

    public function branchHead(string $branch): string
    {
        return $this->resolve($branch);
    }

    public function isAncestor(string $ancestorSha, string $descendantSha): bool
    {
        return $ancestorSha === $descendantSha
            || in_array($ancestorSha, $this->ancestors[$descendantSha] ?? [], true);
    }

    public function tagExists(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function readFile(string $sha, string $path): string
    {
        $key = $sha . ':' . $path;
        if (!isset($this->files[$key])) {
            throw new DomainException('Unknown fake file: ' . $key);
        }

        return $this->files[$key];
    }

    public function commitDate(string $sha): string
    {
        return $this->date;
    }

    public function previousRelease(
        string $baseSha,
        string $tagPrefix,
        ?string $initialRef,
        ?string $excludeTag = null,
    ): PreviousRelease {
        return $this->previous;
    }

    public function commitsBetween(string $fromSha, string $toSha): array
    {
        return $this->commits;
    }
}
