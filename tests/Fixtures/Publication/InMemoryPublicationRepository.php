<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Publication;

use LibreCode\ReleaseTool\Application\Publication\Port\PublicationRepository;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedRelease;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublisherRun;

final class InMemoryPublicationRepository implements PublicationRepository
{
    public function __construct(
        public ?PublishedRelease $release,
        public ?PublisherRun $run,
        private readonly string $assetBytes,
    ) {
    }

    public function release(string $repository, int $releaseId): ?PublishedRelease
    {
        return $this->release;
    }

    public function publisherRun(
        string $repository,
        string $workflow,
        string $headSha,
        ?string $publishedAt,
    ): ?PublisherRun {
        return $this->run;
    }

    public function downloadAsset(string $repository, int $assetId, string $targetPath): void
    {
        file_put_contents($targetPath, $this->assetBytes);
    }
}
