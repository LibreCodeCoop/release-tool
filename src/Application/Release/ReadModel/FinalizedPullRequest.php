<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class FinalizedPullRequest
{
    /** @param list<string> $changedFiles */
    public function __construct(
        public int $number,
        public string $url,
        public string $baseBranch,
        public bool $merged,
        public ?string $mergeCommitSha,
        public array $changedFiles,
    ) {
    }
}
