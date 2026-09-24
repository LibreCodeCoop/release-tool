<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

final readonly class StableBranchSelection
{
    public function __construct(
        public string $currentBranch,
        public ?int $currentMajor,
        public ?string $latestBranch,
        public ?int $latestMajor,
    ) {
    }

    public function isLatest(): bool
    {
        return $this->latestBranch !== null && $this->currentBranch === $this->latestBranch;
    }

    /** @return array{current_branch:string,current_major:?int,latest_branch:?string,latest_major:?int,is_latest:bool} */
    public function toArray(): array
    {
        return [
            'current_branch' => $this->currentBranch,
            'current_major' => $this->currentMajor,
            'latest_branch' => $this->latestBranch,
            'latest_major' => $this->latestMajor,
            'is_latest' => $this->isLatest(),
        ];
    }
}
