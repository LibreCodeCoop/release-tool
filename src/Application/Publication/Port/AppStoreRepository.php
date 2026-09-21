<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication\Port;

interface AppStoreRepository
{
    public function hasRelease(string $apiUrl, string $appId, string $version): bool;
}
