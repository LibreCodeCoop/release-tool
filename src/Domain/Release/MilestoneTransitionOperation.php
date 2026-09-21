<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class MilestoneTransitionOperation implements JsonSerializable
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public string $type,
        public array $details,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'details' => $this->details];
    }
}
