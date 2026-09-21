<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Version;

use DomainException;
use LibreCode\ReleaseTool\Domain\Release\ReleaseActivity;

final readonly class VersionPolicy
{
    public function __construct(private VersionTransitionPolicy $transitions = new VersionTransitionPolicy())
    {
    }

    public function propose(Version $current, ReleaseActivity $activity, ReleaseChannel $channel): Version
    {
        if (!$activity->hasReleasableActivity()) {
            throw new DomainException('No releasable activity found.');
        }

        if ($current->development || $current->channel !== null) {
            return $this->transitions->transition($current, $channel);
        }

        $base = $activity->hasFeaturePullRequest()
            ? new Version($current->major, $current->minor + 1, 0)
            : new Version($current->major, $current->minor, $current->patch + 1);

        return $channel === ReleaseChannel::Final
            ? $base
            : $this->transitions->transition($base, $channel);
    }

    public function bumpReason(ReleaseActivity $activity): string
    {
        return $activity->hasFeaturePullRequest() ? 'feature-pr' : 'releasable-activity';
    }
}
