<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact\Port;

interface ArchiveExtractor
{
    public function extract(string $archive, string $destination): void;
}
