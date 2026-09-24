<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\ReleaseNotes;

final readonly class ReleaseNotesResult
{
    /** @param list<string> $lines */
    public function __construct(
        public array $lines,
        public int $pullRequestCount,
        public int $commitFallbackCount,
    ) {
    }

    public function changeCount(): int
    {
        return count($this->lines);
    }
}
