<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Security;

use LibreCode\ReleaseTool\Domain\Security\RepositoryPermission;

final readonly class RepositoryAuthorization
{
    public function __construct(
        public string $repository,
        public string $actor,
        public RepositoryPermission $minimum,
        public RepositoryPermission $actual,
    ) {
    }

    public function authorized(): bool
    {
        return $this->actual->satisfies($this->minimum);
    }

    /** @return array{actor:string,repository:string,minimum_permission:string,actual_permission:string,authorized:bool} */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor,
            'repository' => $this->repository,
            'minimum_permission' => $this->minimum->value,
            'actual_permission' => $this->actual->value,
            'authorized' => $this->authorized(),
        ];
    }
}
