<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\ReleaseNotes\Port;

use LibreCode\ReleaseTool\Application\ReleaseNotes\AssociatedPullRequest;

interface AssociatedPullRequestRepository
{
    /** @return list<AssociatedPullRequest> */
    public function forCommit(string $repository, string $sha): array;
}
