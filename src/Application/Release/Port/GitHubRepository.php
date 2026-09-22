<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;

interface GitHubRepository
{
    /**
     * @return list<PullRequestInfo>
     */
    public function closedPullRequests(string $repository, string $baseBranch): array;

    /**
     * @return list<PullRequestInfo>
     */
    public function openPullRequests(string $repository, string $baseBranch): array;

    /**
     * @return list<MilestoneInfo>
     */
    public function openMilestones(string $repository): array;

    public function releaseExists(string $repository, string $tag): bool;
}
