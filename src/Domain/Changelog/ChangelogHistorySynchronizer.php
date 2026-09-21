<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Changelog;

use DomainException;

final class ChangelogHistorySynchronizer
{
    public function synchronize(string $current, string $version, string $exactSection): string
    {
        $pattern = '/^## (?:\\[)?' . preg_quote($version, '/') . '(?:\\])?(?:\\s|$)[\\s\\S]*?(?=^## (?:\\[)?\\d+\\.\\d+\\.\\d+|\\z)/m';
        if (preg_match($pattern, $current, $match) === 1) {
            if (rtrim($match[0]) === rtrim($exactSection)) {
                return $current;
            }

            throw new DomainException(sprintf(
                'Aggregate changelog already contains version %s with different content.',
                $version,
            ));
        }

        $offset = strlen($current);
        if (preg_match('/^## (?:\\[)?\\d+\\.\\d+\\.\\d+/m', $current, $heading, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $heading[0][1];
        }

        $prefix = substr($current, 0, $offset);
        $suffix = substr($current, $offset);

        return rtrim($prefix) . "\n\n" . rtrim($exactSection) . "\n\n" . ltrim($suffix, "\n");
    }
}
