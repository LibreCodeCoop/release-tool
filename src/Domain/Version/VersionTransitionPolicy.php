<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Version;

use DomainException;

final class VersionTransitionPolicy
{
    public function transition(Version $current, ReleaseChannel $requested): Version
    {
        if ($current->development) {
            return $this->startPrerelease($current, $requested);
        }

        if ($current->channel === null) {
            if ($requested === ReleaseChannel::Final) {
                return $current;
            }

            return new Version($current->major, $current->minor, $current->patch, $requested, 1);
        }

        if ($requested->rank() < $current->channel->rank()) {
            throw new DomainException(sprintf(
                'Cannot move release channel backwards from %s to %s.',
                $current->channel->value,
                $requested->value,
            ));
        }

        if ($requested === ReleaseChannel::Final) {
            return $current->base();
        }

        if ($requested === $current->channel) {
            return new Version(
                $current->major,
                $current->minor,
                $current->patch,
                $requested,
                ($current->prereleaseNumber ?? 0) + 1,
            );
        }

        return new Version($current->major, $current->minor, $current->patch, $requested, 1);
    }

    public function validateOverride(Version $current, Version $override, ReleaseChannel $requested): void
    {
        if ($override->compare($current) < 0) {
            throw new DomainException('Version override cannot regress the current version.');
        }

        if ($requested === ReleaseChannel::Final && $override->channel !== null) {
            throw new DomainException('Final release override cannot contain a prerelease suffix.');
        }

        if ($requested !== ReleaseChannel::Final && $override->channel !== $requested) {
            throw new DomainException(sprintf('Version override must use the %s channel.', $requested->value));
        }
    }

    private function startPrerelease(Version $current, ReleaseChannel $requested): Version
    {
        if ($requested === ReleaseChannel::Final) {
            return $current->base();
        }

        return new Version($current->major, $current->minor, $current->patch, $requested, 1);
    }
}
