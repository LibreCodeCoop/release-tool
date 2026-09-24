<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Git;

use LibreCode\ReleaseTool\Application\ReleaseNotes\Port\ReleaseNotesGitRepository;
use LibreCode\ReleaseTool\Application\ReleaseNotes\Port\ReleaseNotesGitRepositoryFactory;

final class LocalReleaseNotesGitRepositoryFactory implements ReleaseNotesGitRepositoryFactory
{
    public function create(string $workingDirectory): ReleaseNotesGitRepository
    {
        return new LocalReleaseNotesGitRepository($workingDirectory);
    }
}
