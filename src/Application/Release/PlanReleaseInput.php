<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final readonly class PlanReleaseInput
{
    public function __construct(
        public string $branch,
        public ?string $ref,
        public ?string $versionOverride,
        public ReleaseChannel $channel,
        public bool $ignoreOpenBackport,
        public bool $createFollowUpMilestone,
        public ReleaseMode $mode,
        public ?string $safePublicText,
    ) {
    }
}
