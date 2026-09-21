<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;

interface ReleaseMetadataReader
{
    public function read(ConsumerConfig $config, string $sha): ReleaseMetadata;
}
