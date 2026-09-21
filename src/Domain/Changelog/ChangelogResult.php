<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Changelog;

final readonly class ChangelogResult
{
    public function __construct(
        public string $targetPath,
        public string $releaseSection,
        public string $content,
    ) {
    }
}
