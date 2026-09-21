<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class PreviousRelease
{
    public function __construct(
        public ?string $tag,
        public string $sha,
        public string $comparisonRef,
    ) {
    }
}
