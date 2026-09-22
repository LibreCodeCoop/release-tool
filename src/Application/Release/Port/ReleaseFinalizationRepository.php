<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

use LibreCode\ReleaseTool\Application\Release\HistorySyncRequest;
use LibreCode\ReleaseTool\Application\Release\ReadModel\FinalizedPullRequest;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;

interface ReleaseFinalizationRepository
{
    public function pullRequest(string $repository, int $number): FinalizedPullRequest;

    public function branchHead(string $repository, string $branch): string;

    /** @return list<string> */
    public function branches(string $repository): array;

    public function publishHistorySynchronization(HistorySyncRequest $request): HistorySynchronization;
}
