<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Release;

use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;

final readonly class StaticMetadataReader implements ReleaseMetadataReader
{
    public function __construct(private ReleaseMetadata $metadata)
    {
    }

    public function read(ConsumerConfig $config, string $sha): ReleaseMetadata
    {
        return $this->metadata;
    }
}
