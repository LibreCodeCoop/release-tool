<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Integration\Application\Publication;

use DateTimeImmutable;
use DomainException;
use LibreCode\ReleaseTool\Application\Artifact\ArtifactValidator;
use LibreCode\ReleaseTool\Application\Publication\PublicationVerifier;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedAsset;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedRelease;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublisherRun;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Release\ReleaseDraft;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Infrastructure\Archive\PharArchiveReaderFactory;
use LibreCode\ReleaseTool\Tests\Fixtures\Publication\InMemoryPublicationRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Publication\StaticAppStoreRepository;
use Phar;
use PharData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicationVerifierTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testSuccessfulVerificationBindsPublisherArtifactAndAppStore(): void
    {
        $artifact = $this->artifact();
        try {
            $bytes = (string) file_get_contents($artifact);
            $digest = hash('sha256', $bytes);
            $release = new PublishedRelease(
                101,
                'https://example.test/releases/101',
                'v15.0.4',
                self::SHA,
                false,
                false,
                '2026-09-21T18:00:00Z',
                [new PublishedAsset(303, 'libresign-v15.0.4.tar.gz', 'asset', $digest)],
            );
            $run = new PublisherRun(
                202,
                'https://example.test/actions/runs/202',
                self::SHA,
                'release',
                'completed',
                'success',
                '2026-09-21T18:01:00Z',
            );
            $repository = new InMemoryPublicationRepository($release, $run, $bytes);
            $verification = (new PublicationVerifier(
                $repository,
                new StaticAppStoreRepository(true),
                new ArtifactValidator(new PharArchiveReaderFactory()),
            ))->verify(
                $this->config(),
                $this->draft(),
                $this->prepared(),
                new DateTimeImmutable('2026-09-21T19:00:00Z'),
            );

            self::assertTrue($verification->success);
            self::assertTrue($verification->releasePublished);
            self::assertTrue($verification->publisherSucceeded);
            self::assertTrue($verification->artifactValid);
            self::assertTrue($verification->appStoreVisible);
            self::assertSame($digest, $verification->artifactSha256);
            self::assertSame([], $verification->errors);
            self::assertSame(0, $repository->downloadCount);
        } finally {
            $this->removeArtifact($artifact);
        }
    }

    public function testFailedPublisherStopsBeforeAppStoreAndArtifactVerification(): void
    {
        $artifact = $this->artifact();
        try {
            $bytes = (string) file_get_contents($artifact);
            $release = new PublishedRelease(
                101,
                'https://example.test/releases/101',
                'v15.0.4',
                self::SHA,
                false,
                false,
                '2026-09-21T18:00:00Z',
                [new PublishedAsset(303, 'libresign-v15.0.4.tar.gz', 'asset', null)],
            );
            $run = new PublisherRun(
                202,
                'https://example.test/actions/runs/202',
                self::SHA,
                'release',
                'completed',
                'failure',
                '2026-09-21T18:01:00Z',
            );
            $repository = new InMemoryPublicationRepository($release, $run, $bytes);
            $verification = (new PublicationVerifier(
                $repository,
                new StaticAppStoreRepository(false),
                new ArtifactValidator(new PharArchiveReaderFactory()),
            ))->verify($this->config(), $this->draft(), $this->prepared());

            self::assertFalse($verification->success);
            self::assertFalse($verification->publisherSucceeded);
            self::assertFalse($verification->appStoreVisible);
            self::assertStringContainsString('Publisher workflow', implode("\n", $verification->errors));
            self::assertStringNotContainsString('App Store', implode("\n", $verification->errors));
            self::assertSame(0, $repository->downloadCount);
        } finally {
            $this->removeArtifact($artifact);
        }
    }

    public function testPendingAppStoreDoesNotBlockPublicationOrDownloadReleaseAsset(): void
    {
        $artifact = $this->artifact();
        try {
            $bytes = (string) file_get_contents($artifact);
            $digest = hash('sha256', $bytes);
            $release = new PublishedRelease(
                101,
                'https://example.test/releases/101',
                'v15.0.4',
                self::SHA,
                false,
                false,
                '2026-09-21T18:00:00Z',
                [new PublishedAsset(303, 'libresign-v15.0.4.tar.gz', 'asset', $digest)],
            );
            $run = new PublisherRun(
                202,
                'https://example.test/actions/runs/202',
                self::SHA,
                'release',
                'completed',
                'success',
                '2026-09-21T18:01:00Z',
            );
            $repository = new InMemoryPublicationRepository($release, $run, $bytes);

            $verification = (new PublicationVerifier(
                $repository,
                new StaticAppStoreRepository(false),
                new ArtifactValidator(new PharArchiveReaderFactory()),
            ))->verify($this->config(), $this->draft(), $this->prepared());

            self::assertTrue($verification->success);
            self::assertTrue($verification->publisherSucceeded);
            self::assertFalse($verification->appStoreVisible);
            self::assertTrue($verification->artifactValid);
            self::assertSame($digest, $verification->artifactSha256);
            self::assertSame(0, $repository->downloadCount);
        } finally {
            $this->removeArtifact($artifact);
        }
    }

    public function testDoesNotQueryAppStoreBeforePublisherConverges(): void
    {
        $release = new PublishedRelease(
            101,
            'https://example.test/releases/101',
            'v15.0.4',
            self::SHA,
            false,
            false,
            '2026-09-21T18:00:00Z',
            [new PublishedAsset(303, 'libresign-v15.0.4.tar.gz', 'asset', null)],
        );
        $run = new PublisherRun(
            202,
            'https://example.test/actions/runs/202',
            self::SHA,
            'release',
            'in_progress',
            null,
            '2026-09-21T18:01:00Z',
        );
        $appStore = new class() implements \LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository {
            public int $calls = 0;

            public function hasRelease(string $apiUrl, string $appId, string $version): bool
            {
                ++$this->calls;
                return false;
            }
        };

        $verification = (new PublicationVerifier(
            new InMemoryPublicationRepository($release, $run, ''),
            $appStore,
            new ArtifactValidator(new PharArchiveReaderFactory()),
        ))->verify($this->config(), $this->draft(), $this->prepared());

        self::assertFalse($verification->success);
        self::assertSame(0, $appStore->calls);
        self::assertStringContainsString('Publisher workflow', implode("\n", $verification->errors));
    }

    public function testUsesPlatformScopedAppStoreFeedForStableBranch(): void
    {
        $artifact = $this->artifact();
        try {
            $bytes = (string) file_get_contents($artifact);
            $digest = hash('sha256', $bytes);
            $release = new PublishedRelease(
                101,
                'https://example.test/releases/101',
                'v15.0.4',
                self::SHA,
                false,
                false,
                '2026-09-21T18:00:00Z',
                [new PublishedAsset(303, 'libresign-v15.0.4.tar.gz', 'asset', $digest)],
            );
            $run = new PublisherRun(
                202,
                'https://example.test/actions/runs/202',
                self::SHA,
                'release',
                'completed',
                'success',
                '2026-09-21T18:01:00Z',
            );
            $appStore = new class() implements \LibreCode\ReleaseTool\Application\Publication\Port\AppStoreRepository {
                public ?string $apiUrl = null;

                public function hasRelease(string $apiUrl, string $appId, string $version): bool
                {
                    $this->apiUrl = $apiUrl;
                    return true;
                }
            };

            $verification = (new PublicationVerifier(
                new InMemoryPublicationRepository($release, $run, $bytes),
                $appStore,
                new ArtifactValidator(new PharArchiveReaderFactory()),
            ))->verify($this->config(), $this->draft(), $this->prepared());

            self::assertTrue($verification->success);
            self::assertSame(
                'https://apps.nextcloud.com/api/v1/platform/35.0.0/apps.json',
                $appStore->apiUrl,
            );
        } finally {
            $this->removeArtifact($artifact);
        }
    }

    public function testMismatchedUpstreamArtifactsFailBeforeExternalVerification(): void
    {
        $draft = $this->draft();
        $prepared = $this->prepared();
        $badDraft = new ReleaseDraft(
            $draft->id,
            'other-prepared',
            $draft->milestoneTransitionId,
            $draft->releaseId,
            $draft->releaseUrl,
            $draft->tagName,
            $draft->targetSha,
            $draft->prerelease,
            $draft->bodySha256,
            $draft->draft,
            $draft->ready,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not reference');

        (new PublicationVerifier(
            new InMemoryPublicationRepository(null, null, ''),
            new StaticAppStoreRepository(false),
            new ArtifactValidator(new PharArchiveReaderFactory()),
        ))->verify($this->config(), $badDraft, $prepared);
    }

    private function artifact(): string
    {
        $directory = sys_get_temp_dir() . '/publication-verifier-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $tarPath = $directory . '/libresign-v15.0.4.tar';
        $archive = new PharData($tarPath);
        $archive->addFromString('libresign/appinfo/info.xml', '<info><id>libresign</id><version>15.0.4</version></info>');
        $archive->addFromString('libresign/CHANGELOG.md', "# Changelog\n\n## 15.0.4 - 2026-09-21\n\n### Fixed\n- Fixed.\n");
        $archive->addFromString('libresign/appinfo/routes.php', '<?php');
        $archive->compress(Phar::GZ);
        unset($archive);
        @unlink($tarPath);
        return $tarPath . '.gz';
    }

    private function removeArtifact(string $path): void
    {
        $cleanup = new Process(['rm', '-rf', dirname($path)]);
        $cleanup->run();
    }

    private function config(): ConsumerConfig
    {
        return new ConsumerConfig(
            1, 'libresign', 'main', 'LibreSign/libresign', '^stable(?<nextcloud>\\d+)$',
            'appinfo/info.xml', ['package.json', 'package-lock.json'], 'v', 'reachable-tag', null,
            'per-major', 'docs/changelogs/changelog-{major}.md', 'CHANGELOG.md',
            'Next Patch ({nextcloud})', 'Next RC ({nextcloud})', 'maintain', 'maintain',
            ['make', 'appstore'], ['appinfo'], ['tests'],
            'appstore-build-publish.yml', '{app}-{tag}.tar.gz',
            'https://apps.nextcloud.com/api/v1/platform/{nextcloud}.0.0/apps.json',
        );
    }

    private function draft(): ReleaseDraft
    {
        return new ReleaseDraft(
            'draft-id',
            'prepared-id',
            'milestone-id',
            101,
            'https://example.test/releases/101',
            'v15.0.4',
            self::SHA,
            false,
            str_repeat('b', 64),
            true,
            true,
        );
    }

    private function prepared(): PreparedRelease
    {
        return new PreparedRelease(
            'prepared-id',
            'plan-id',
            'preparation-id',
            'LibreSign/libresign',
            'stable35',
            77,
            'https://example.test/pull/77',
            self::SHA,
            '15.0.4',
            'v15.0.4',
            ReleaseChannel::Final,
            ReleaseMode::Normal,
            'docs/changelogs/changelog-15.md',
            '## 15.0.4',
            str_repeat('c', 64),
            [],
            new HistorySynchronization(
                HistorySyncState::AlreadySynchronized,
                'main',
                'docs/changelogs/changelog-15.md',
            ),
        );
    }
}
