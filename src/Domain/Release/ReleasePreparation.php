<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final readonly class ReleasePreparation implements JsonSerializable
{
    /**
     * @param list<FileChange> $fileChanges
     */
    public function __construct(
        public string $id,
        public string $releasePlanId,
        public string $repository,
        public string $targetBranch,
        public string $planningBaseSha,
        public string $version,
        public ReleaseChannel $channel,
        public ReleaseMode $mode,
        public array $fileChanges,
        public string $changelogSection,
        public string $changelogSha256,
        public string $generatedBranch,
        public string $prMarker,
        public ?int $pullRequestNumber,
        public ?string $pullRequestUrl,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'release_plan_id' => $this->releasePlanId,
            'repository' => $this->repository,
            'target_branch' => $this->targetBranch,
            'planning_base_sha' => $this->planningBaseSha,
            'version' => $this->version,
            'channel' => $this->channel->value,
            'mode' => $this->mode->value,
            'file_changes' => $this->fileChanges,
            'changelog' => [
                'section' => $this->changelogSection,
                'sha256' => $this->changelogSha256,
            ],
            'generated_branch' => $this->generatedBranch,
            'pr_marker' => $this->prMarker,
            'pull_request' => $this->pullRequestNumber !== null ? [
                'number' => $this->pullRequestNumber,
                'url' => $this->pullRequestUrl,
            ] : null,
            'merge_preconditions' => [
                'target_branch' => $this->targetBranch,
                'expected_base_sha' => $this->planningBaseSha,
                'allowed_files' => array_map(
                    static fn (FileChange $change): string => $change->path,
                    $this->fileChanges,
                ),
            ],
        ];
    }

    public function withPullRequest(int $number, string $url): self
    {
        return new self(
            $this->id,
            $this->releasePlanId,
            $this->repository,
            $this->targetBranch,
            $this->planningBaseSha,
            $this->version,
            $this->channel,
            $this->mode,
            $this->fileChanges,
            $this->changelogSection,
            $this->changelogSha256,
            $this->generatedBranch,
            $this->prMarker,
            $number,
            $url,
        );
    }
}
