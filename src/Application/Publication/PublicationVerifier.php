<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication;

use DateTimeImmutable;
use DomainException;
use LibreCode\ReleaseTool\Application\Artifact\ArtifactValidator;
use LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository;
use LibreCode\ReleaseTool\Application\Publication\Port\PublicationRepository;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedAsset;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Release\PublicationVerification;
use LibreCode\ReleaseTool\Domain\Release\ReleaseDraft;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use RuntimeException;

final readonly class PublicationVerifier
{
    public function __construct(
        private PublicationRepository $publication,
        private AppStoreRepository $appStore,
        private ArtifactValidator $artifactValidator,
    ) {
    }

    public function verify(
        ConsumerConfig $config,
        ReleaseDraft $draft,
        PreparedRelease $prepared,
        ?DateTimeImmutable $verifiedAt = null,
    ): PublicationVerification {
        $this->assertIdentity($config, $draft, $prepared);

        $workflow = $config->publicationPublisherWorkflow;
        $assetTemplate = $config->publicationAssetName;
        $appStoreApi = $config->publicationAppStoreApi;
        if ($workflow === null || $assetTemplate === null || $appStoreApi === null) {
            throw new DomainException('Consumer publication configuration is incomplete.');
        }

        $assetName = strtr($assetTemplate, [
            '{app}' => $config->appId,
            '{version}' => $prepared->version,
            '{tag}' => $prepared->tagName,
        ]);

        $errors = [];
        $releasePublished = false;
        $publisherRunId = null;
        $publisherRunUrl = null;
        $publisherConclusion = null;
        $publisherSucceeded = false;
        $artifactSha256 = null;
        $artifactValidationId = null;
        $artifactValid = false;
        $appStoreVisible = false;
        $asset = null;

        $release = $this->publication->release($prepared->repository, $draft->releaseId);
        if ($release === null) {
            $errors[] = sprintf('GitHub Release %d was not found.', $draft->releaseId);
        } else {
            if ($release->draft) {
                $errors[] = 'GitHub Release is still a draft.';
            } else {
                $releasePublished = true;
            }
            if ($release->tagName !== $prepared->tagName) {
                $errors[] = sprintf(
                    'Published release tag mismatch: expected %s, got %s.',
                    $prepared->tagName,
                    $release->tagName,
                );
            }
            if ($release->targetSha !== $prepared->finalSha) {
                $errors[] = sprintf(
                    'Published release target mismatch: expected %s, got %s.',
                    $prepared->finalSha,
                    $release->targetSha,
                );
            }

            $run = $this->publication->publisherRun(
                $prepared->repository,
                $workflow,
                $prepared->finalSha,
                $release->publishedAt,
            );
            if ($run === null) {
                $errors[] = sprintf('No completed publisher run found for workflow %s.', $workflow);
            } else {
                $publisherRunId = $run->id;
                $publisherRunUrl = $run->url;
                $publisherConclusion = $run->conclusion;
                $publisherSucceeded = $run->status === 'completed' && $run->conclusion === 'success';
                if (!$publisherSucceeded) {
                    $errors[] = sprintf(
                        'Publisher workflow did not complete successfully (status=%s, conclusion=%s).',
                        $run->status,
                        $run->conclusion ?? 'null',
                    );
                }
            }

            $asset = $this->findAsset($release->assets, $assetName);
            if ($asset === null) {
                $errors[] = sprintf('Expected release asset not found: %s.', $assetName);
            }
        }

        // Do not fetch the App Store feed while GitHub-side publication is still
        // converging. The feed is comparatively large and cannot prove publication
        // before the publisher has completed and attached the expected asset.
        if ($releasePublished && $publisherSucceeded && $asset !== null) {
            try {
                $appStoreVisible = $this->appStore->hasRelease(
                    $this->appStoreApiForRelease($appStoreApi, $config, $prepared),
                    $config->appId,
                    $prepared->version,
                );
                if (!$appStoreVisible) {
                    $errors[] = sprintf(
                        'App Store does not expose %s version %s.',
                        $config->appId,
                        $prepared->version,
                    );
                }
            } catch (\Throwable $exception) {
                $errors[] = 'App Store verification failed: ' . $exception->getMessage();
            }
        }

        // Artifact validation is intentionally deferred until all external publication
        // signals have converged. This keeps retry polling lightweight and guarantees
        // that the release asset is downloaded at most once during a successful run.
        if ($releasePublished && $publisherSucceeded && $asset !== null && $appStoreVisible) {
            [$artifactSha256, $artifactValidationId, $artifactValid, $artifactErrors] =
                $this->validateArtifact($asset, $prepared, $config);
            foreach ($artifactErrors as $error) {
                $errors[] = $error;
            }
        }

        $timestamp = ($verifiedAt ?? new DateTimeImmutable())->format(DATE_ATOM);
        $success = $errors === [];
        $identity = json_encode([
            'release_draft_id' => $draft->id,
            'prepared_release_id' => $prepared->id,
            'release_id' => $draft->releaseId,
            'publisher_run_id' => $publisherRunId,
            'artifact_sha256' => $artifactSha256,
            'appstore_visible' => $appStoreVisible,
            'success' => $success,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new PublicationVerification(
            hash('sha256', $identity),
            $draft->id,
            $prepared->id,
            $prepared->repository,
            $prepared->tagName,
            $prepared->finalSha,
            $draft->releaseId,
            $draft->releaseUrl,
            $releasePublished,
            $workflow,
            $publisherRunId,
            $publisherRunUrl,
            $publisherConclusion,
            $publisherSucceeded,
            $assetName,
            $artifactSha256,
            $artifactValidationId,
            $artifactValid,
            $appStoreApi,
            $config->appId,
            $prepared->version,
            $appStoreVisible,
            $timestamp,
            $prepared->mode === ReleaseMode::Security,
            $errors,
            $success,
        );
    }

    private function appStoreApiForRelease(
        string $apiUrl,
        ConsumerConfig $config,
        PreparedRelease $prepared,
    ): string {
        $pattern = '~' . str_replace('~', '\\~', $config->stablePattern) . '~';
        if (preg_match($pattern, $prepared->branch, $matches) !== 1) {
            return $apiUrl;
        }

        $nextcloud = $matches['nextcloud'] ?? null;
        if (!is_string($nextcloud) || !ctype_digit($nextcloud)) {
            return $apiUrl;
        }

        $suffix = '/apps.json';
        if (
            !str_ends_with($apiUrl, $suffix)
            || preg_match('~/platform/\\d+\\.\\d+\\.\\d+/apps\\.json$~', $apiUrl) === 1
        ) {
            return $apiUrl;
        }

        return substr($apiUrl, 0, -strlen($suffix))
            . sprintf('/platform/%d.0.0/apps.json', (int) $nextcloud);
    }

    private function assertIdentity(ConsumerConfig $config, ReleaseDraft $draft, PreparedRelease $prepared): void
    {
        if ($draft->preparedReleaseId !== $prepared->id) {
            throw new DomainException('ReleaseDraft does not reference the supplied PreparedRelease.');
        }
        if ($draft->tagName !== $prepared->tagName || $draft->targetSha !== $prepared->finalSha) {
            throw new DomainException('ReleaseDraft identity does not match PreparedRelease.');
        }
        if ($config->repository !== null && $config->repository !== $prepared->repository) {
            throw new DomainException('Consumer repository does not match PreparedRelease repository.');
        }
    }

    /** @param list<PublishedAsset> $assets */
    private function findAsset(array $assets, string $name): ?PublishedAsset
    {
        $matches = array_values(array_filter(
            $assets,
            static fn (PublishedAsset $asset): bool => $asset->name === $name,
        ));

        if (count($matches) > 1) {
            throw new DomainException(sprintf('GitHub Release contains duplicate asset name: %s.', $name));
        }

        return $matches[0] ?? null;
    }

    /**
     * @return array{0:?string,1:?string,2:bool,3:list<string>}
     */
    private function validateArtifact(
        PublishedAsset $asset,
        PreparedRelease $prepared,
        ConsumerConfig $config,
    ): array {
        $directory = sys_get_temp_dir() . '/release-tool-publication-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create temporary directory: %s', $directory));
        }
        $path = $directory . '/' . basename($asset->name);

        try {
            $this->publication->downloadAsset($prepared->repository, $asset->id, $path);
            $validation = $this->artifactValidator->validate(
                $path,
                $config,
                $config->appId,
                $prepared->version,
                $asset->sha256,
            );
            $errors = [];
            foreach ($validation->errors as $error) {
                $errors[] = 'Artifact validation: ' . $error;
            }
            return [$validation->sha256, $validation->id, $validation->valid, $errors];
        } catch (\Throwable $exception) {
            return [null, null, false, ['Artifact validation failed: ' . $exception->getMessage()]];
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }
}
