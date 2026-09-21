<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Changelog;

use DomainException;

final class ChangelogMigrator
{
    /**
     * @return array<int, string>
     */
    public function splitByMajor(string $content): array
    {
        $pattern = '/^## (?:\[)?(?<version>\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\.\d+)?)(?:\])?(?:\s+-\s+[^\n]+)?\n[\s\S]*?(?=^## (?:\[)?\d+\.\d+\.\d+|\z)/m';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            throw new DomainException('Could not parse changelog.');
        }

        $byMajor = [];
        foreach ($matches as $match) {
            $major = (int) explode('.', $match['version'])[0];
            $byMajor[$major] ??= "# Changelog\n\n";
            $byMajor[$major] .= rtrim($match[0]) . "\n\n";
        }

        if ($byMajor === []) {
            throw new DomainException('No release sections found in changelog.');
        }

        krsort($byMajor);

        return array_map(static fn (string $value): string => rtrim($value) . "\n", $byMajor);
    }
}
