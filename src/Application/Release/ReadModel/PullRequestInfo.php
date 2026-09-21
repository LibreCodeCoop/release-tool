<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class PullRequestInfo
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public string $baseBranch,
        public ?string $mergeCommitSha,
        public ?string $mergedAt,
        public string $url,
        public array $labels,
        public string $author,
    ) {
    }

    public function isMerged(): bool
    {
        return $this->mergedAt !== null && $this->mergeCommitSha !== null;
    }
}
