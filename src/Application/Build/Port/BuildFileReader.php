<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Build\Port;

interface BuildFileReader
{
    public function read(string $root, string $path): ?string;
}
