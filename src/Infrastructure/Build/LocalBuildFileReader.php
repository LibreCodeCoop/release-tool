<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Build;

use DomainException;
use LibreCode\ReleaseTool\Application\Build\Port\BuildFileReader;

final class LocalBuildFileReader implements BuildFileReader
{
    public function read(string $root, string $path): ?string
    {
        $file = rtrim($root, '/').'/'.$path;
        if (!is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            throw new DomainException(sprintf('Unable to read build metadata: %s', $path));
        }

        return $content;
    }
}
