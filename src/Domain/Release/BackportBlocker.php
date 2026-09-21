<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

final readonly class BackportBlocker
{
    public function __construct(
        public int $number,
        public string $title,
        public string $url,
    ) {
    }
}
