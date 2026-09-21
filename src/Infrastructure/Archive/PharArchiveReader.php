<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Archive;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReader;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;

final class PharArchiveReader implements ArchiveReader
{
    private PharData $archive;

    /** @var array<string, true> */
    private array $entries = [];

    public function __construct(private readonly string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Archive is not readable: %s', $path));
        }

        $this->assertSupportedTarArchive();
        $this->assertTarPathsSafe();

        try {
            $this->archive = new PharData($path);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Unsupported or malformed archive: ' . $exception->getMessage(), 0, $exception);
        }

        $iterator = new RecursiveIteratorIterator($this->archive, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                continue;
            }

            $relative = str_replace('\\', '/', $entry->getPathName());
            $marker = '/' . basename($path) . '/';
            $position = strpos($relative, $marker);
            if ($position !== false) {
                $relative = substr($relative, $position + strlen($marker));
            } elseif (str_starts_with($relative, 'phar://')) {
                $relative = substr($relative, strlen('phar://'));
                $archivePosition = strpos($relative, basename($path) . '/');
                if ($archivePosition !== false) {
                    $relative = substr($relative, $archivePosition + strlen(basename($path)) + 1);
                }
            }

            $relative = ltrim($relative, '/');
            $this->assertSafePath($relative);
            if (isset($this->entries[$relative])) {
                throw new InvalidArgumentException(sprintf('Archive contains duplicate path: %s', $relative));
            }
            $this->entries[$relative] = true;
        }

        if ($this->entries === []) {
            throw new InvalidArgumentException('Archive contains no files.');
        }

        ksort($this->entries, SORT_STRING);
    }

    public function paths(): array
    {
        return array_keys($this->entries);
    }

    public function read(string $path): string
    {
        if (!isset($this->entries[$path])) {
            throw new InvalidArgumentException(sprintf('Archive path not found: %s', $path));
        }

        try {
            $content = $this->archive[$path]->getContent();
        } catch (\Throwable $exception) {
            throw new RuntimeException(sprintf('Could not read archive path %s: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_string($content)) {
            throw new RuntimeException(sprintf('Could not read archive path: %s', $path));
        }

        return $content;
    }

    private function assertSupportedTarArchive(): void
    {
        $name = strtolower($this->path);
        if (
            !str_ends_with($name, '.tar')
            && !str_ends_with($name, '.tar.gz')
            && !str_ends_with($name, '.tgz')
        ) {
            throw new InvalidArgumentException('Unsupported archive format. Supported formats: .tar, .tar.gz, .tgz.');
        }
    }

    private function assertTarPathsSafe(): void
    {
        $handle = $this->openTarStream();
        $pendingLongName = null;

        try {
            while (true) {
                $header = $this->readExact($handle, 512);
                if ($header === '') {
                    break;
                }
                if (strlen($header) !== 512) {
                    throw new InvalidArgumentException('Malformed TAR archive: truncated header.');
                }
                if ($header === str_repeat("\0", 512)) {
                    break;
                }

                $name = rtrim(substr($header, 0, 100), "\0 ");
                $prefix = rtrim(substr($header, 345, 155), "\0 ");
                $path = $prefix === '' ? $name : $prefix . '/' . $name;
                $sizeField = trim(substr($header, 124, 12), "\0 ");
                if ($sizeField === '' || preg_match('/^[0-7]+$/', $sizeField) !== 1) {
                    throw new InvalidArgumentException('Malformed TAR archive: invalid entry size.');
                }
                $size = octdec($sizeField);
                $type = substr($header, 156, 1);

                $payload = $size > 0 ? $this->readExact($handle, $size) : '';
                if (strlen($payload) !== $size) {
                    throw new InvalidArgumentException('Malformed TAR archive: truncated entry payload.');
                }
                $padding = (512 - ($size % 512)) % 512;
                if ($padding > 0 && strlen($this->readExact($handle, $padding)) !== $padding) {
                    throw new InvalidArgumentException('Malformed TAR archive: truncated entry padding.');
                }

                if ($type === 'L') {
                    $pendingLongName = rtrim($payload, "\0\n");
                    $this->assertSafePath($pendingLongName);
                    continue;
                }

                if ($type === 'x' || $type === 'g') {
                    foreach (preg_split('/\n/', $payload) ?: [] as $line) {
                        if (preg_match('/^\d+ path=(.+)$/', $line, $matches) === 1) {
                            $this->assertSafePath($matches[1]);
                        }
                    }
                    continue;
                }

                $effectivePath = $pendingLongName ?? $path;
                $pendingLongName = null;
                $this->assertSafePath($effectivePath);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function openTarStream()
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException(sprintf('Archive is not readable: %s', $this->path));
        }

        $magic = fread($handle, 2);
        fclose($handle);

        if ($magic === "\x1f\x8b") {
            if (!function_exists('gzopen')) {
                throw new InvalidArgumentException('Gzip-compressed TAR archives require the zlib extension.');
            }
            $gzip = gzopen($this->path, 'rb');
            if ($gzip === false) {
                throw new InvalidArgumentException('Could not open gzip-compressed TAR archive.');
            }

            $temporary = fopen('php://temp', 'w+b');
            if ($temporary === false) {
                gzclose($gzip);
                throw new RuntimeException('Could not allocate temporary stream for archive validation.');
            }

            while (!gzeof($gzip)) {
                $chunk = gzread($gzip, 1024 * 1024);
                if ($chunk === false) {
                    gzclose($gzip);
                    fclose($temporary);
                    throw new InvalidArgumentException('Could not decompress TAR archive.');
                }
                fwrite($temporary, $chunk);
            }
            gzclose($gzip);
            rewind($temporary);

            return $temporary;
        }

        $stream = fopen($this->path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException(sprintf('Archive is not readable: %s', $this->path));
        }

        return $stream;
    }

    /** @param resource $handle */
    private function readExact($handle, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($buffer));
            if ($chunk === false) {
                throw new InvalidArgumentException('Could not read TAR archive.');
            }
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function assertSafePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path) === 1
        ) {
            throw new InvalidArgumentException(sprintf('Unsafe archive path: %s', $path));
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('Unsafe archive path: %s', $path));
            }
        }
    }
}
