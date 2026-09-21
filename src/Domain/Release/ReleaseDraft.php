<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class ReleaseDraft implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $preparedReleaseId,
        public string $milestoneTransitionId,
        public int $releaseId,
        public string $releaseUrl,
        public string $tagName,
        public string $targetSha,
        public bool $prerelease,
        public string $bodySha256,
        public bool $draft,
        public bool $ready,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'prepared_release_id' => $this->preparedReleaseId,
            'milestone_transition_id' => $this->milestoneTransitionId,
            'github_release' => [
                'id' => $this->releaseId,
                'url' => $this->releaseUrl,
            ],
            'tag_name' => $this->tagName,
            'target_sha' => $this->targetSha,
            'prerelease' => $this->prerelease,
            'body_sha256' => $this->bodySha256,
            'draft' => $this->draft,
            'ready' => $this->ready,
        ];
    }
}
