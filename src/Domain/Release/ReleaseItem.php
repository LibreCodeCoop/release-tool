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
        public ?string $url = null,
        public ?string $conventionalScope = null,
        public bool $backport = false,
        public bool $maintenance = false,
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

    public function isBackport(): bool
    {
        return $this->backport;
    }

    public function isMaintenance(): bool
    {
        return $this->maintenance;
    }
}
