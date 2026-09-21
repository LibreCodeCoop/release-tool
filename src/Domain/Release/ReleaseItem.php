<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

final readonly class ReleaseItem
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public string $kind,
        public string $title,
        public ?int $pullRequestNumber = null,
        public ?string $conventionalType = null,
        public array $labels = [],
        public bool $publicSecurityEntry = false,
    ) {
    }

    public function isDependency(): bool
    {
        return $this->kind === 'dependency';
    }

    public function isTranslation(): bool
    {
        return $this->kind === 'translation';
    }
}
