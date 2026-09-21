<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;

interface ReleasePlanning
{
    public function plan(ConsumerConfig $config, PlanReleaseInput $input): ReleasePlan;
}
