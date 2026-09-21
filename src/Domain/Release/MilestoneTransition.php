<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class MilestoneTransition implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $preparedReleaseId,
        public int $releasedMilestoneNumber,
        public string $releasedMilestoneUrl,
        public string $finalTitle,
        public ?int $followUpMilestoneNumber,
        public ?string $followUpMilestoneUrl,
        public int $movedIssues,
        public int $movedPullRequests,
        public bool $alreadyApplied = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'prepared_release_id' => $this->preparedReleaseId,
            'released_milestone' => [
                'number' => $this->releasedMilestoneNumber,
                'url' => $this->releasedMilestoneUrl,
                'final_title' => $this->finalTitle,
            ],
            'follow_up_milestone' => $this->followUpMilestoneNumber === null ? null : [
                'number' => $this->followUpMilestoneNumber,
                'url' => $this->followUpMilestoneUrl,
            ],
            'moved' => [
                'issues' => $this->movedIssues,
                'pull_requests' => $this->movedPullRequests,
            ],
            'already_applied' => $this->alreadyApplied,
        ];
    }
}
