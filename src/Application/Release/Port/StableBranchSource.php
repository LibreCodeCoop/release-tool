<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

interface StableBranchSource
{
    /** @return list<string> */
    public function stableBranches(string $repository): array;
}
