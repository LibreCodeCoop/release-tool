<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

final readonly class ConventionalTitle
{
    public function __construct(
        public string $normalizedTitle,
        public ?string $type,
        public ?string $scope,
        public string $subject,
        public bool $breaking,
    ) {
    }
}
