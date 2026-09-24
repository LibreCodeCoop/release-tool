<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

final readonly class ArtifactRestore
{
    public function __construct(
        public int $artifactId,
        public ?int $workflowRunId,
        public string $name,
        public string $createdAt,
    ) {
    }

    /** @return array{artifact_id:int,workflow_run_id:?int,name:string,created_at:string} */
    public function toArray(): array
    {
        return [
            'artifact_id' => $this->artifactId,
            'workflow_run_id' => $this->workflowRunId,
            'name' => $this->name,
            'created_at' => $this->createdAt,
        ];
    }
}
