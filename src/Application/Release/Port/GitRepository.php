<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

use LibreCode\ReleaseTool\Application\Release\ReadModel\CommitInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;

interface GitRepository
{
    public function repositoryIdentity(): ?string;

    public function resolve(string $ref): string;

    public function branchHead(string $branch): string;

    public function isAncestor(string $ancestorSha, string $descendantSha): bool;

    public function previousRelease(
        string $baseSha,
        string $tagPrefix,
        ?string $initialRef,
    ): PreviousRelease;

    /**
     * @return list<CommitInfo>
     */
    public function commitsBetween(string $fromSha, string $toSha): array;
}
