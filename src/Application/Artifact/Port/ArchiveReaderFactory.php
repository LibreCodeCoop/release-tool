<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact\Port;

interface ArchiveReaderFactory
{
    public function open(string $path): ArchiveReader;
}
