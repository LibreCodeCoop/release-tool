<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact\Port;

use LibreCode\ReleaseTool\Application\Artifact\ActionsArtifact;
use LibreCode\ReleaseTool\Application\Artifact\ActionsWorkflowRun;

interface ActionsArtifactRepository
{
    /** @return list<ActionsArtifact> */
    public function artifacts(string $repository, string $name): array;

    public function workflowRun(string $repository, int $runId): ActionsWorkflowRun;

    public function download(string $url, string $destination): void;
}
