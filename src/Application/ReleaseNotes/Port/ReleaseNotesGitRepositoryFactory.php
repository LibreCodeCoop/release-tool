<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\ReleaseNotes\Port;

interface ReleaseNotesGitRepositoryFactory
{
    public function create(string $workingDirectory): ReleaseNotesGitRepository;
}
