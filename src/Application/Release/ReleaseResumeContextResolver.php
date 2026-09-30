<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseFinalizationRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseResumeContext;

final readonly class ReleaseResumeContextResolver
{
    public function __construct(
        private ReleaseFinalizationRepository $github,
    ) {
    }

    public function resolve(string $repository, int $pullRequestNumber): ReleaseResumeContext
    {
        $pullRequest = $this->github->pullRequest($repository, $pullRequestNumber);

        if (!$pullRequest->merged) {
            throw new DomainException(sprintf('PR #%d is not merged.', $pullRequestNumber));
        }
        if ($pullRequest->headBranch === null || !str_starts_with($pullRequest->headBranch, 'release-tool/')) {
            throw new DomainException(sprintf('PR #%d is not a release-tool preparation PR.', $pullRequestNumber));
        }
        if ($pullRequest->body === null || !str_contains($pullRequest->body, '<!-- release-tool:preparation ')) {
            throw new DomainException(sprintf('PR #%d does not contain a release-tool preparation marker.', $pullRequestNumber));
        }
        if ($pullRequest->mergedBy === null || $pullRequest->mergedBy === '') {
            throw new DomainException(sprintf('PR #%d is missing merger metadata.', $pullRequestNumber));
        }

        return new ReleaseResumeContext($pullRequestNumber, $pullRequest->baseBranch, $pullRequest->mergedBy);
    }
}
