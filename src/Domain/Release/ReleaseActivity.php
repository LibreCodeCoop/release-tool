<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

final readonly class ReleaseActivity
{
    /**
     * @param list<ReleaseItem> $items
     */
    public function __construct(public array $items)
    {
    }

    public function hasReleasableActivity(): bool
    {
        return $this->items !== [];
    }

    public function hasFeaturePullRequest(): bool
    {
        foreach ($this->items as $item) {
            if (
                $item->kind === 'pull_request'
                && $item->conventionalType === 'feat'
                && !$item->isBackport()
                && !$item->isMaintenance()
            ) {
                return true;
            }
        }

        return false;
    }
}
