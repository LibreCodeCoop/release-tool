<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

final readonly class ArtifactWorkflowOrigin
{
    public function __construct(
        public string $event,
        public string $workflowPath,
    ) {
    }

    public function matches(ActionsWorkflowRun $run): bool
    {
        return $run->event === $this->event && $run->path === $this->workflowPath;
    }
}
