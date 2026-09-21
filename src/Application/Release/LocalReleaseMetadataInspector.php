<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Application\Release\ReadModel\LocalReleaseMetadata;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;

final readonly class LocalReleaseMetadataInspector
{
    public function __construct(
        private ReleaseMetadataReader $metadataReader,
    ) {
    }

    public function inspect(ConsumerConfig $config, string $sha, string $changelogContent): LocalReleaseMetadata
    {
        $metadata = $this->metadataReader->read($config, $sha);
        $version = (string) $metadata->version;
        $major = $metadata->version->major;
        $changelogPath = str_replace('{major}', (string) $major, $config->changelogPath);
        $required = !$metadata->version->development;
        $present = $this->containsReleaseSection($changelogContent, $version);

        if ($required && !$present) {
            throw new DomainException(sprintf(
                'Release changelog does not contain version %s: %s',
                $version,
                $changelogPath,
            ));
        }

        return new LocalReleaseMetadata(
            $version,
            $major,
            $metadata->version->development,
            $changelogPath,
            $required,
            $present,
        );
    }

    private function containsReleaseSection(string $content, string $version): bool
    {
        return preg_match(
            '/^## (?:\\[)?' . preg_quote($version, '/') . '(?:\\])?(?:\\s+-|\\s*$)/m',
            $content,
        ) === 1;
    }
}
