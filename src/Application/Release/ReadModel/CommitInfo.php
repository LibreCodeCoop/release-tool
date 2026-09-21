<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class CommitInfo
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public string $sha,
        public string $subject,
        public array $paths,
    ) {
    }
}
