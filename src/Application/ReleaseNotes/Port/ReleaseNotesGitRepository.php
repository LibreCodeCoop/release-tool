<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\ReleaseNotes\Port;

interface ReleaseNotesGitRepository
{
    /** @return list<string> */
    public function commits(string $fromRef, string $toRef, int $fallbackLimit): array;

    public function subject(string $sha): string;
}
