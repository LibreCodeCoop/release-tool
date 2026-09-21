<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact\Port;

interface ArchiveReader
{
    /** @return list<string> */
    public function paths(): array;

    public function read(string $path): string;
}
