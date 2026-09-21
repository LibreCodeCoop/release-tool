<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Version\Version;

final readonly class GitReleaseMetadataReader implements ReleaseMetadataReader
{
    public function __construct(private GitRepository $git)
    {
    }

    public function read(ConsumerConfig $config, string $sha): ReleaseMetadata
    {
        $xml = simplexml_load_string($this->git->readFile($sha, $config->versionSource));
        if ($xml === false) {
            throw new DomainException(sprintf('Invalid XML version source: %s', $config->versionSource));
        }

        $versionText = trim((string) ($xml->version ?? ''));
        if ($versionText === '') {
            throw new DomainException(sprintf('Missing <version> in %s.', $config->versionSource));
        }
        $version = Version::parse($versionText);

        $nextcloud = $xml->dependencies->nextcloud ?? null;
        if ($nextcloud === null) {
            throw new DomainException(sprintf('Missing Nextcloud compatibility in %s.', $config->versionSource));
        }

        $min = (string) ($nextcloud['min-version'] ?? '');
        $max = (string) ($nextcloud['max-version'] ?? '');
        if (preg_match('/^\d+$/', $min) !== 1 || preg_match('/^\d+$/', $max) !== 1) {
            throw new DomainException('Nextcloud min-version/max-version must be integer majors.');
        }

        $mirrors = [];
        foreach ($config->versionMirrors as $path) {
            $decoded = json_decode($this->git->readFile($sha, $path), true);
            if (!is_array($decoded) || !isset($decoded['version']) || !is_string($decoded['version'])) {
                throw new DomainException(sprintf('Version mirror has no string version: %s', $path));
            }
            $mirrors[$path] = $decoded['version'];
            if ($decoded['version'] !== $versionText) {
                throw new DomainException(sprintf(
                    'Version mirror mismatch: %s has %s, expected %s from %s.',
                    $path,
                    $decoded['version'],
                    $versionText,
                    $config->versionSource,
                ));
            }
        }

        return new ReleaseMetadata($version, (int) $min, (int) $max, $mirrors);
    }
}
