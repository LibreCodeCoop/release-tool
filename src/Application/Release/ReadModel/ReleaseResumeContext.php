<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class ReleaseResumeContext
{
    public function __construct(
        public int $pullRequestNumber,
        public string $baseBranch,
        public string $merger,
    ) {
    }
}
