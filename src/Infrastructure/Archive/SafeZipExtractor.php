<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Archive;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveExtractor;
use PharData;
use RuntimeException;

final class SafeZipExtractor implements ArchiveExtractor
{
    public function extract(string $archive, string $destination): void
    {
        if (!is_file($archive) || !is_readable($archive)) {
            throw new InvalidArgumentException(sprintf('Artifact archive is not readable: %s', $archive));
        }

        $paths = $this->centralDirectoryPaths($archive);
        if ($paths === []) {
            throw new InvalidArgumentException('Artifact archive is empty.');
        }

        if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
            throw new RuntimeException(sprintf('Could not create artifact destination: %s', $destination));
        }
        $root = realpath($destination);
        if ($root === false) {
            throw new RuntimeException(sprintf('Could not resolve artifact destination: %s', $destination));
        }

        try {
            $zip = new PharData($archive, 0, null, \Phar::ZIP);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                'Could not open artifact ZIP: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        foreach ($paths as $path) {
            $directoryEntry = str_ends_with($path, '/');
            $relative = $directoryEntry ? rtrim($path, '/') : $path;
            if ($relative === '') {
                continue;
            }
            $target = $this->safeTarget($root, $relative);
            if ($directoryEntry) {
                if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
                    throw new RuntimeException(sprintf('Could not create artifact directory: %s', $target));
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException(sprintf('Could not create artifact directory: %s', $parent));
            }

            try {
                $content = $zip[$path]->getContent();
            } catch (\Throwable $exception) {
                throw new InvalidArgumentException(
                    sprintf('Could not read artifact ZIP entry %s: %s', $path, $exception->getMessage()),
                    0,
                    $exception,
                );
            }

            if (file_put_contents($target, $content) === false) {
                throw new RuntimeException(sprintf('Could not write artifact file: %s', $target));
            }
        }
    }

    private function safeTarget(string $root, string $relative): string
    {
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            $next = $current . '/' . $segment;
            if (is_link($next)) {
                throw new InvalidArgumentException(sprintf('unsafe artifact path through symlink: %s', $relative));
            }

            if (file_exists($next)) {
                $resolved = realpath($next);
                if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
                    throw new InvalidArgumentException(sprintf('unsafe artifact path: %s', $relative));
                }
            }

            $current = $next;
        }

        return $current;
    }

    /** @return list<string> */
    private function centralDirectoryPaths(string $archive): array
    {
        $data = file_get_contents($archive);
        if (!is_string($data)) {
            throw new InvalidArgumentException(sprintf('Artifact archive is not readable: %s', $archive));
        }

        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || strlen($data) - $eocd < 22) {
            throw new InvalidArgumentException('Malformed artifact ZIP: end-of-central-directory not found.');
        }

        $metadata = unpack(
            'ventries/Vsize/Voffset',
            substr($data, $eocd + 10, 10),
        );
        if (!is_array($metadata)) {
            throw new InvalidArgumentException('Malformed artifact ZIP central directory.');
        }

        $entries = (int) $metadata['entries'];
        $size = (int) $metadata['size'];
        $offset = (int) $metadata['offset'];
        if ($entries === 0 || $size === 0 || $offset < 0 || $offset + $size > strlen($data)) {
            throw new InvalidArgumentException('Malformed artifact ZIP central directory.');
        }

        $paths = [];
        $cursor = $offset;
        for ($index = 0; $index < $entries; ++$index) {
            $header = substr($data, $cursor, 46);
            if (strlen($header) !== 46 || !str_starts_with($header, "PK\x01\x02")) {
                throw new InvalidArgumentException('Malformed artifact ZIP central directory entry.');
            }

            $lengths = unpack('vname/vextra/vcomment', substr($header, 28, 6));
            if (!is_array($lengths)) {
                throw new InvalidArgumentException('Malformed artifact ZIP central directory entry.');
            }

            $nameLength = (int) $lengths['name'];
            $extraLength = (int) $lengths['extra'];
            $commentLength = (int) $lengths['comment'];
            $name = substr($data, $cursor + 46, $nameLength);
            if (strlen($name) !== $nameLength) {
                throw new InvalidArgumentException('Malformed artifact ZIP filename.');
            }
            $this->assertSafePath($name);
            $paths[] = $name;
            $cursor += 46 + $nameLength + $extraLength + $commentLength;
        }

        return $paths;
    }

    private function assertSafePath(string $path): void
    {
        $effective = str_ends_with($path, '/') ? rtrim($path, '/') : $path;
        if (
            $effective === ''
            || str_contains($effective, "\0")
            || str_contains($effective, '\\')
            || str_starts_with($effective, '/')
            || preg_match('/^[A-Za-z]:\//', $effective) === 1
        ) {
            throw new InvalidArgumentException(sprintf('unsafe artifact path: %s', $path));
        }

        foreach (explode('/', $effective) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('unsafe artifact path: %s', $path));
            }
        }
    }
}
