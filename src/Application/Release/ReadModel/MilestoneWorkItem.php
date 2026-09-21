<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class MilestoneWorkItem
{
    public function __construct(
        public int $number,
        public bool $pullRequest,
        public string $url,
    ) {
    }
}
