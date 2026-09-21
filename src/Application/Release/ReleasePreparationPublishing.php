<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;

interface ReleasePreparationPublishing
{
    public function publish(ReleasePreparation $preparation): ReleasePreparation;
}
