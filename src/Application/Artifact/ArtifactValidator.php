<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReaderFactory;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use RuntimeException;

final readonly class ArtifactValidator
{
    public function __construct(private ArchiveReaderFactory $archives)
    {
    }

    public function validate(
        string $archivePath,
        ConsumerConfig $config,
        string $expectedAppId,
        string $expectedVersion,
        ?string $expectedDigest = null,
    ): ArtifactValidation {
        if ($expectedAppId !== $config->appId) {
            throw new InvalidArgumentException(sprintf(
                'Expected app id %s does not match consumer configuration app id %s.',
                $expectedAppId,
                $config->appId,
            ));
        }

        if (!is_file($archivePath) || !is_readable($archivePath)) {
            throw new InvalidArgumentException(sprintf('Archive is not readable: %s', $archivePath));
        }

        $digest = hash_file('sha256', $archivePath);
        if (!is_string($digest)) {
            throw new RuntimeException(sprintf('Could not calculate SHA-256 for archive: %s', $archivePath));
        }

        $errors = [];
        $warnings = [];
        if ($expectedDigest !== null && !hash_equals(strtolower($expectedDigest), strtolower($digest))) {
            $errors[] = sprintf('Artifact digest mismatch: expected %s, got %s.', $expectedDigest, $digest);
        }

        $archive = $this->archives->open($archivePath);
        $paths = $archive->paths();

        $topLevels = [];
        foreach ($paths as $path) {
            $top = explode('/', $path, 2)[0];
            $topLevels[$top] = true;
        }
        $topLevelNames = array_keys($topLevels);
        sort($topLevelNames, SORT_STRING);

        if ($topLevelNames !== [$expectedAppId]) {
            $errors[] = sprintf(
                'Archive must contain exactly one top-level app directory named %s; found: %s.',
                $expectedAppId,
                implode(', ', $topLevelNames),
            );
        }

        $infoPath = $expectedAppId . '/appinfo/info.xml';
        $actualAppId = null;
        $actualVersion = null;

        if (!in_array($infoPath, $paths, true)) {
            $errors[] = sprintf('Missing required package metadata: %s.', $infoPath);
        } else {
            try {
                $xml = @simplexml_load_string($archive->read($infoPath));
                if ($xml === false) {
                    throw new RuntimeException('Invalid XML.');
                }
                $actualAppId = trim((string) ($xml->id ?? ''));
                $actualVersion = trim((string) ($xml->version ?? ''));

                if ($actualAppId === '') {
                    $errors[] = 'Packaged appinfo/info.xml does not contain an app id.';
                    $actualAppId = null;
                } elseif ($actualAppId !== $expectedAppId) {
                    $errors[] = sprintf('Packaged app id mismatch: expected %s, got %s.', $expectedAppId, $actualAppId);
                }

                if ($actualVersion === '') {
                    $errors[] = 'Packaged appinfo/info.xml does not contain a version.';
                    $actualVersion = null;
                } elseif ($actualVersion !== $expectedVersion) {
                    $errors[] = sprintf(
                        'Packaged app version mismatch: expected %s, got %s.',
                        $expectedVersion,
                        $actualVersion,
                    );
                }
            } catch (\Throwable $exception) {
                $errors[] = 'Could not parse packaged appinfo/info.xml: ' . $exception->getMessage();
            }
        }

        $packageChangelog = $expectedAppId . '/' . ltrim($config->packageRootChangelog, '/');
        $changelogFound = in_array($packageChangelog, $paths, true);
        $releaseSectionFound = false;
        if (!$changelogFound) {
            $errors[] = sprintf('Missing configured package changelog: %s.', $packageChangelog);
        } else {
            $content = $archive->read($packageChangelog);
            $pattern = '/^##\s+\[?v?' . preg_quote($expectedVersion, '/') . '\]?(?:\s|$)/m';
            $releaseSectionFound = preg_match($pattern, $content) === 1;
            if (!$releaseSectionFound) {
                $errors[] = sprintf(
                    'Package changelog does not contain a release section for %s.',
                    $expectedVersion,
                );
            }
        }

        $missingRequired = [];
        foreach ($config->packageRequiredPaths as $requiredPath) {
            $prefix = $expectedAppId . '/' . trim($requiredPath, '/');
            if (!$this->containsPath($paths, $prefix)) {
                $missingRequired[] = $requiredPath;
                $errors[] = sprintf('Missing configured required package path: %s.', $requiredPath);
            }
        }

        $forbiddenFound = [];
        foreach ($config->packageForbiddenPaths as $forbiddenPath) {
            $prefix = $expectedAppId . '/' . trim($forbiddenPath, '/');
            if ($this->containsPath($paths, $prefix)) {
                $forbiddenFound[] = $forbiddenPath;
                $errors[] = sprintf('Forbidden package path is present: %s.', $forbiddenPath);
            }
        }

        $structure = [
            'top_level_paths' => $topLevelNames,
            'info_xml' => in_array($infoPath, $paths, true),
            'required_paths' => $config->packageRequiredPaths,
            'missing_required_paths' => $missingRequired,
            'forbidden_paths' => $config->packageForbiddenPaths,
            'forbidden_paths_found' => $forbiddenFound,
        ];
        $changelog = [
            'path' => $config->packageRootChangelog,
            'present' => $changelogFound,
            'release_section_found' => $releaseSectionFound,
        ];

        $payload = [
            'artifact' => basename($archivePath),
            'sha256' => $digest,
            'expected_app_id' => $expectedAppId,
            'actual_app_id' => $actualAppId,
            'expected_version' => $expectedVersion,
            'actual_version' => $actualVersion,
            'changelog' => $changelog,
            'structure' => $structure,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $id = hash('sha256', $encoded);

        return new ArtifactValidation(
            $id,
            $archivePath,
            basename($archivePath),
            $digest,
            $expectedAppId,
            $actualAppId,
            $expectedVersion,
            $actualVersion,
            $changelog,
            $structure,
            $warnings,
            $errors,
            $errors === [],
        );
    }

    /** @param list<string> $paths */
    private function containsPath(array $paths, string $prefix): bool
    {
        foreach ($paths as $path) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
