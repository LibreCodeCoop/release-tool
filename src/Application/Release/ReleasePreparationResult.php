<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;

final readonly class ReleasePreparationResult
{
    public function __construct(
        public ReleasePreparation $preparation,
        public string $diff,
    ) {
    }
}
