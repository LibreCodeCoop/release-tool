<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class MilestoneInfo
{
    public function __construct(
        public int $number,
        public string $title,
        public string $url,
    ) {
    }
}
