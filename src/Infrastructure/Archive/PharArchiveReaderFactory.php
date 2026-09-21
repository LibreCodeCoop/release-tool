<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Archive;

use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReader;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReaderFactory;

final class PharArchiveReaderFactory implements ArchiveReaderFactory
{
    public function open(string $path): ArchiveReader
    {
        return new PharArchiveReader($path);
    }
}
