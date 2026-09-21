<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

use LibreCode\ReleaseTool\Domain\Version\Version;

final readonly class ReleaseMetadata
{
    /**
     * @param array<string, string> $mirrors
     */
    public function __construct(
        public Version $version,
        public int $nextcloudMin,
        public int $nextcloudMax,
        public array $mirrors,
    ) {
    }
}
