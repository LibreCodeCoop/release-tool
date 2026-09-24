<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

interface ReleasePreflightRepository
{
    /** @return array{state:string, open_issues:int}|null */
    public function milestone(string $repository, string $title): ?array;

    public function openItemCount(string $repository, string $query): int;
}
