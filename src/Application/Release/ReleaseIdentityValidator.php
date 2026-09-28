<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Version\Version;

final readonly class ReleaseIdentityValidator
{
    public function __construct(
        private GitRepository $git,
        private ReleaseMetadataReader $metadataReader,
    ) {
    }

    /**
     * @return array{tag:string,version:string,sha:string}
     */
    public function validate(
        ConsumerConfig $config,
        string $tag,
        string $ref = 'HEAD',
        bool $requireTagExists = false,
    ): array {
        $tag = trim($tag);
        if ($tag === '') {
            throw new DomainException('Release tag cannot be empty.');
        }

        if (!str_starts_with($tag, $config->tagPrefix)) {
            throw new DomainException(sprintf(
                "Release tag '%s' must start with configured prefix '%s'.",
                $tag,
                $config->tagPrefix,
            ));
        }

        $versionText = substr($tag, strlen($config->tagPrefix));
        try {
            $tagVersion = Version::parse($versionText);
        } catch (InvalidArgumentException) {
            throw new DomainException(sprintf(
                "Release tag '%s' has an invalid version. Expected '%s<version>', for example '%s13.4.3'.",
                $tag,
                $config->tagPrefix,
                $config->tagPrefix,
            ));
        }

        $sha = $this->git->resolve($ref);
        $metadata = $this->metadataReader->read($config, $sha);
        $appVersion = (string) $metadata->version;
        $expectedTag = $config->tagPrefix . $appVersion;

        if ((string) $tagVersion !== $appVersion || $tag !== $expectedTag) {
            throw new DomainException(sprintf(
                "Release tag '%s' does not match app version '%s'; expected '%s'.",
                $tag,
                $appVersion,
                $expectedTag,
            ));
        }

        if ($requireTagExists) {
            if (!$this->git->tagExists($tag)) {
                throw new DomainException(sprintf(
                    "Release tag '%s' does not exist in the repository.",
                    $tag,
                ));
            }

            $tagSha = $this->git->resolve($tag);
            if ($tagSha !== $sha) {
                throw new DomainException(sprintf(
                    "Release tag '%s' points to commit '%s', but ref '%s' resolves to '%s'. Build the release from the tag commit.",
                    $tag,
                    $tagSha,
                    $ref,
                    $sha,
                ));
            }
        }

        return [
            'tag' => $tag,
            'version' => $appVersion,
            'sha' => $sha,
        ];
    }
}
