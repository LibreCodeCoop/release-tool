<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Application\Release\Port\StableBranchSource;

final readonly class StableBranchSelector
{
    public function __construct(private StableBranchSource $branches)
    {
    }

    public function select(string $repository, string $currentBranch): StableBranchSelection
    {
        $currentMajor = self::major($currentBranch);
        $byMajor = [];

        foreach ($this->branches->stableBranches($repository) as $branch) {
            $major = self::major($branch);
            if ($major !== null) {
                $byMajor[$major] = $branch;
            }
        }

        if ($byMajor === []) {
            return new StableBranchSelection($currentBranch, $currentMajor, null, null);
        }

        $latestMajor = max(array_keys($byMajor));

        return new StableBranchSelection(
            $currentBranch,
            $currentMajor,
            $byMajor[$latestMajor],
            $latestMajor,
        );
    }

    private static function major(string $branch): ?int
    {
        if (preg_match('/^stable([1-9][0-9]*)$/', $branch, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
