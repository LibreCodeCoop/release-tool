<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

final readonly class ActionsWorkflowRun
{
    public function __construct(
        public string $event,
        public string $path,
    ) {
    }
}
