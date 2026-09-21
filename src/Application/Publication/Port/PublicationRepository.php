<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication\Port;

use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedRelease;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublisherRun;

interface PublicationRepository
{
    public function release(string $repository, int $releaseId): ?PublishedRelease;

    public function publisherRun(
        string $repository,
        string $workflow,
        string $headSha,
        ?string $publishedAt,
    ): ?PublisherRun;

    public function downloadAsset(string $repository, int $assetId, string $targetPath): void;
}
