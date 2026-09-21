<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;
use LibreCode\ReleaseTool\Domain\Security\PublicReleaseText;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final readonly class ReleasePlan implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $activity
     * @param list<BackportBlocker> $backportBlockers
     * @param list<string> $warnings
     */
    public function __construct(
        public string $id,
        public string $repository,
        public string $consumerId,
        public string $branch,
        public int $nextcloudMajor,
        public int $appMajor,
        public string $planningBaseSha,
        public string $previousTag,
        public string $previousTagSha,
        public string $currentVersion,
        public string $proposedVersion,
        public ?string $explicitVersionOverride,
        public ReleaseChannel $channel,
        public string $bumpReason,
        public array $activity,
        public string $changelogTarget,
        public ?array $milestone,
        public array $backportBlockers,
        public bool $ignoreOpenBackport,
        public bool $createFollowUpMilestone,
        public ReleaseMode $mode,
        public PublicReleaseText $publicReleaseText,
        public array $warnings,
        public bool $ready,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'repository' => $this->repository,
            'consumer_id' => $this->consumerId,
            'branch' => $this->branch,
            'nextcloud_major' => $this->nextcloudMajor,
            'app_major' => $this->appMajor,
            'planning_base_sha' => $this->planningBaseSha,
            'previous_release' => [
                'tag' => $this->previousTag,
                'sha' => $this->previousTagSha,
            ],
            'current_version' => $this->currentVersion,
            'proposed_version' => $this->proposedVersion,
            'explicit_version_override' => $this->explicitVersionOverride,
            'channel' => $this->channel->value,
            'bump_reason' => $this->bumpReason,
            'activity' => $this->activity,
            'changelog_target' => $this->changelogTarget,
            'milestone' => $this->milestone,
            'backport_blockers' => array_map(
                static fn (BackportBlocker $blocker): array => [
                    'number' => $blocker->number,
                    'title' => $blocker->title,
                    'url' => $blocker->url,
                ],
                $this->backportBlockers,
            ),
            'ignore_open_backport' => $this->ignoreOpenBackport,
            'create_follow_up_milestone' => $this->createFollowUpMilestone,
            'mode' => $this->mode->value,
            'public_release_text' => $this->publicReleaseText,
            'warnings' => $this->warnings,
            'ready' => $this->ready,
        ];
    }
}
