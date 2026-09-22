<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Release;

use LibreCode\ReleaseTool\Application\Release\Port\GitHubRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;

final class InMemoryGitHubRepository implements GitHubRepository
{
    /**
     * @param list<PullRequestInfo> $closed
     * @param list<PullRequestInfo> $open
     * @param list<MilestoneInfo> $milestones
     * @param list<string> $releases
     */
    public function __construct(
        private readonly array $closed = [],
        private readonly array $open = [],
        private readonly array $milestones = [],
        private readonly array $releases = [],
    ) {
    }

    public function closedPullRequests(string $repository, string $baseBranch): array
    {
        return array_values(array_filter(
            $this->closed,
            static fn (PullRequestInfo $pullRequest): bool => $pullRequest->baseBranch === $baseBranch,
        ));
    }

    public function openPullRequests(string $repository, string $baseBranch): array
    {
        return array_values(array_filter(
            $this->open,
            static fn (PullRequestInfo $pullRequest): bool => $pullRequest->baseBranch === $baseBranch,
        ));
    }

    public function openMilestones(string $repository): array
    {
        return $this->milestones;
    }

    public function releaseExists(string $repository, string $tag): bool
    {
        return in_array($tag, $this->releases, true);
    }
}
