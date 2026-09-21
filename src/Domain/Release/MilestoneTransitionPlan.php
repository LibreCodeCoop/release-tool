<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class MilestoneTransitionPlan implements JsonSerializable
{
    /** @param list<MilestoneTransitionOperation> $operations */
    public function __construct(
        public string $id,
        public string $preparedReleaseId,
        public string $repository,
        public int $releasedMilestoneNumber,
        public string $releasedMilestoneUrl,
        public string $currentTitle,
        public string $finalTitle,
        public ?string $followUpTitle,
        public array $operations,
        public bool $alreadyApplied = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'prepared_release_id' => $this->preparedReleaseId,
            'repository' => $this->repository,
            'released_milestone' => [
                'number' => $this->releasedMilestoneNumber,
                'url' => $this->releasedMilestoneUrl,
                'current_title' => $this->currentTitle,
                'final_title' => $this->finalTitle,
            ],
            'follow_up_title' => $this->followUpTitle,
            'already_applied' => $this->alreadyApplied,
            'operations' => $this->operations,
        ];
    }
}
