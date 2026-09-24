<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReaderFactory;
use RuntimeException;

final readonly class NextcloudPackageValidator
{
    public function __construct(private ArchiveReaderFactory $archives)
    {
    }

    public function validate(string $artifact, string $appName, string $version): void
    {
        if (!is_file($artifact)) {
            throw new InvalidArgumentException(sprintf('artifact does not exist: %s', $artifact));
        }

        $archive = $this->archives->open($artifact);
        $paths = $archive->paths();
        if ($paths === []) {
            throw new InvalidArgumentException('artifact is empty');
        }

        $topLevels = [];
        foreach ($paths as $path) {
            $topLevels[explode('/', $path, 2)[0]] = true;
        }
        $names = array_keys($topLevels);
        sort($names, SORT_STRING);
        if ($names !== [$appName]) {
            throw new InvalidArgumentException(sprintf(
                "artifact must contain only the top-level directory '%s'; found %s",
                $appName,
                json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ));
        }

        $infoPath = $appName . '/appinfo/info.xml';
        if (!in_array($infoPath, $paths, true)) {
            throw new InvalidArgumentException(sprintf('artifact is missing %s', $infoPath));
        }

        $xml = @simplexml_load_string($archive->read($infoPath));
        if ($xml === false) {
            throw new RuntimeException(sprintf('cannot parse %s', $infoPath));
        }

        $declared = isset($xml->version) ? (string) $xml->version : null;
        if ($declared !== $version) {
            throw new InvalidArgumentException(sprintf(
                "%s declares version %s, expected '%s'",
                $infoPath,
                $declared === null ? 'null' : "'" . $declared . "'",
                $version,
            ));
        }
    }
}
