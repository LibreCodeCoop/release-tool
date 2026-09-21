<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class HistorySynchronization implements JsonSerializable
{
    public function __construct(
        public HistorySyncState $state,
        public string $targetBranch,
        public string $targetPath,
        public ?string $generatedBranch = null,
        public ?int $pullRequestNumber = null,
        public ?string $pullRequestUrl = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state->value,
            'target_branch' => $this->targetBranch,
            'target_path' => $this->targetPath,
            'generated_branch' => $this->generatedBranch,
            'pull_request' => $this->pullRequestNumber !== null ? [
                'number' => $this->pullRequestNumber,
                'url' => $this->pullRequestUrl,
            ] : null,
        ];
    }
}
