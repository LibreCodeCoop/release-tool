<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Publication;

use LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository;

final readonly class StaticAppStoreRepository implements AppStoreRepository
{
    public function __construct(private bool $visible)
    {
    }

    public function hasRelease(string $apiUrl, string $appId, string $version): bool
    {
        return $this->visible;
    }
}
