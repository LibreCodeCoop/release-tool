<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;

final class ReleaseLineResolver
{
    public function nextcloudMajor(
        ConsumerConfig $config,
        string $branch,
        int $minimum,
        int $maximum,
    ): int {
        if ($branch === $config->mainBranch) {
            if ($minimum !== $maximum) {
                throw new DomainException('Main release line must identify one Nextcloud major for deterministic planning.');
            }
            return $minimum;
        }

        $pattern = '~' . str_replace('~', '\\~', $config->stablePattern) . '~';
        if (preg_match($pattern, $branch, $match) !== 1 || !isset($match['nextcloud'])) {
            throw new DomainException(sprintf('Branch does not match configured stable pattern: %s', $branch));
        }

        $major = (int) $match['nextcloud'];
        if ($major < $minimum || $major > $maximum) {
            throw new DomainException(sprintf(
                'Branch %s maps to Nextcloud %d but app metadata supports %d..%d.',
                $branch,
                $major,
                $minimum,
                $maximum,
            ));
        }
        return $major;
    }
}
