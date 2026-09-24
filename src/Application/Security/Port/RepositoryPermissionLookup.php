<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Security\Port;

use LibreCode\ReleaseTool\Domain\Security\RepositoryPermission;

interface RepositoryPermissionLookup
{
    public function permission(string $repository, string $actor): RepositoryPermission;
}
