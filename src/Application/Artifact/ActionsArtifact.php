<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

final readonly class ActionsArtifact
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $expired,
        public string $createdAt,
        public string $archiveDownloadUrl,
        public ?int $workflowRunId,
        public ?string $headSha,
    ) {
    }
}
