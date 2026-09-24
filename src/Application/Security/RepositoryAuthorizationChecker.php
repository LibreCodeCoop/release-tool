<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Security;

use LibreCode\ReleaseTool\Application\Security\Port\RepositoryPermissionLookup;
use LibreCode\ReleaseTool\Domain\Security\RepositoryPermission;

final readonly class RepositoryAuthorizationChecker
{
    public function __construct(private RepositoryPermissionLookup $permissions)
    {
    }

    public function check(string $repository, string $actor, string $minimum): RepositoryAuthorization
    {
        $minimumPermission = RepositoryPermission::parse($minimum, 'minimum permission');

        return new RepositoryAuthorization(
            $repository,
            $actor,
            $minimumPermission,
            $this->permissions->permission($repository, $actor),
        );
    }
}
