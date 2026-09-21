<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

final readonly class HistorySyncRequest
{
    public function __construct(
        public string $repository,
        public string $targetBranch,
        public string $expectedBaseSha,
        public string $targetPath,
        public string $content,
        public string $generatedBranch,
        public string $marker,
        public string $version,
    ) {
    }
}
