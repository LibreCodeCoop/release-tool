<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\ReleaseNotes;

final readonly class AssociatedPullRequest
{
    public function __construct(
        public int $number,
        public string $title,
        public string $mergedAt,
        public ?string $baseBranch,
    ) {
    }
}
