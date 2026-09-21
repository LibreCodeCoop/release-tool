<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final readonly class PreparedRelease implements JsonSerializable
{
    /** @param array<string, string> $releaseFileDigests */
    public function __construct(
        public string $id,
        public string $releasePlanId,
        public string $releasePreparationId,
        public string $repository,
        public string $branch,
        public int $releasePullRequestNumber,
        public string $releasePullRequestUrl,
        public string $finalSha,
        public string $version,
        public string $tagName,
        public ReleaseChannel $channel,
        public ReleaseMode $mode,
        public string $changelogTarget,
        public string $changelogSection,
        public string $changelogSha256,
        public array $releaseFileDigests,
        public HistorySynchronization $historySynchronization,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'release_plan_id' => $this->releasePlanId,
            'release_preparation_id' => $this->releasePreparationId,
            'repository' => $this->repository,
            'branch' => $this->branch,
            'release_pull_request' => [
                'number' => $this->releasePullRequestNumber,
                'url' => $this->releasePullRequestUrl,
            ],
            'final_sha' => $this->finalSha,
            'version' => $this->version,
            'tag_name' => $this->tagName,
            'channel' => $this->channel->value,
            'mode' => $this->mode->value,
            'changelog' => [
                'target' => $this->changelogTarget,
                'section' => $this->changelogSection,
                'sha256' => $this->changelogSha256,
            ],
            'release_files' => $this->releaseFileDigests,
            'history_synchronization' => $this->historySynchronization,
        ];
    }
}
