<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use DateTimeImmutable;
use DomainException;
use LibreCode\ReleaseTool\Application\Artifact\ArtifactValidator;
use LibreCode\ReleaseTool\Application\Publication\PublicationVerifier;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedAsset;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedRelease;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublisherRun;
use LibreCode\ReleaseTool\Application\Release\MilestoneTransitioner;
use LibreCode\ReleaseTool\Application\Release\PlanReleaseInput;
use LibreCode\ReleaseTool\Application\Release\ReadModel\CommitInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\FinalizedPullRequest;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Application\Release\ReleaseDrafter;
use LibreCode\ReleaseTool\Application\Release\ReleaseFinalizer;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanner;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparer;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Infrastructure\Archive\PharArchiveReaderFactory;
use LibreCode\ReleaseTool\Tests\Fixtures\Publication\InMemoryPublicationRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Publication\StaticAppStoreRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitHubRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryMilestoneRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryReleaseDraftRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryReleaseFinalizationRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\StaticMetadataReader;
use Phar;
use PharData;
use PHPUnit\Framework\TestCase;

final class ReleaseLifecycleTest extends TestCase
{
    private const PREVIOUS = '1111111111111111111111111111111111111111';
    private const MERGE = '2222222222222222222222222222222222222222';
    private const BASE = '3333333333333333333333333333333333333333';
    private const FINAL = '4444444444444444444444444444444444444444';
    private const MAIN = '5555555555555555555555555555555555555555';

    protected function setUp(): void
    {
        putenv('GITHUB_REPOSITORY');
    }

    public function testNormalLifecycleReachesSuccessfulPublicationVerification(): void
    {
        [$plan, $preparation] = $this->planAndPrepare();

        self::assertTrue($plan->ready);
        self::assertSame('15.0.4', $preparation->version);

        [$prepared, $draft] = $this->finalizeAndDraft($preparation);

        $artifact = $this->artifact($prepared->version, $prepared->changelogSection);
        try {
            $bytes = (string) file_get_contents($artifact);
            $digest = hash('sha256', $bytes);
            $release = new PublishedRelease(
                $draft->releaseId,
                $draft->releaseUrl,
                $prepared->tagName,
                $prepared->finalSha,
                false,
                $draft->prerelease,
                '2026-09-21T18:00:00Z',
                [new PublishedAsset(303, 'libresign-' . $prepared->tagName . '.tar.gz', 'asset', $digest)],
            );
            $run = new PublisherRun(
                202,
                'https://example.test/actions/runs/202',
                $prepared->finalSha,
                'release',
                'completed',
                'success',
                '2026-09-21T18:01:00Z',
            );

            $verification = (new PublicationVerifier(
                new InMemoryPublicationRepository($release, $run, $bytes),
                new StaticAppStoreRepository(true),
                new ArtifactValidator(new PharArchiveReaderFactory()),
            ))->verify(
                $this->config(),
                $draft,
                $prepared,
                new DateTimeImmutable('2026-09-21T19:00:00Z'),
            );

            self::assertTrue($verification->success);
            self::assertTrue($verification->releasePublished);
            self::assertTrue($verification->publisherSucceeded);
            self::assertTrue($verification->artifactValid);
            self::assertTrue($verification->appStoreVisible);
        } finally {
            @unlink($artifact);
            @rmdir(dirname($artifact));
        }
    }

    public function testPrereleaseIdentitySurvivesPlanPreparationAndDraft(): void
    {
        [$plan, $preparation] = $this->planAndPrepare(channel: ReleaseChannel::Rc);

        self::assertSame('15.0.4-rc.1', $plan->proposedVersion);
        self::assertSame(ReleaseChannel::Rc, $preparation->channel);

        [, $draft] = $this->finalizeAndDraft($preparation, ReleaseChannel::Rc);

        self::assertSame('v15.0.4-rc.1', $draft->tagName);
        self::assertTrue($draft->prerelease);
        self::assertTrue($draft->ready);
    }

    public function testBackportBlockerRequiresExplicitOverrideBeforePreparation(): void
    {
        $backport = new PullRequestInfo(
            20,
            '[stable35] backport: fix: pending fix',
            '',
            'stable35',
            null,
            null,
            'https://example.test/pull/20',
            ['backport'],
            'contributor',
        );

        [$blocked] = $this->planAndPrepare(openPullRequests: [$backport], prepare: false);
        self::assertFalse($blocked->ready);
        self::assertFalse($blocked->ignoreOpenBackport);
        self::assertCount(1, $blocked->backportBlockers);

        [$overridden, $preparation] = $this->planAndPrepare(
            openPullRequests: [$backport],
            ignoreOpenBackport: true,
        );

        self::assertTrue($overridden->ready);
        self::assertTrue($overridden->ignoreOpenBackport);
        self::assertSame('15.0.4', $preparation->version);
    }

    public function testSecurityLifecycleKeepsPrivateActivityOutOfPublicArtifacts(): void
    {
        [$plan, $preparation] = $this->planAndPrepare(
            mode: ReleaseMode::Security,
            pullRequestTitle: 'fix: PRIVATE-ADVISORY-DO-NOT-PUBLISH',
        );

        self::assertSame('This release includes security fixes.', $plan->publicReleaseText->text);
        self::assertStringContainsString('### Security', $preparation->changelogSection);
        self::assertStringNotContainsString('PRIVATE-ADVISORY-DO-NOT-PUBLISH', $preparation->changelogSection);

        [$prepared, $draft] = $this->finalizeAndDraft(
            $preparation,
            mode: ReleaseMode::Security,
        );

        self::assertSame('deferred_security', $prepared->historySynchronization->state->value);
        self::assertStringNotContainsString('PRIVATE-ADVISORY-DO-NOT-PUBLISH', $prepared->changelogSection);
        self::assertTrue($draft->ready);
    }


    public function testTranslationOnlyDirectCommitProducesPatchRelease(): void
    {
        $translationCommit = new CommitInfo(
            self::BASE,
            'chore(l10n): update translations',
            ['l10n/pt_BR.js', 'l10n/pt_BR.json'],
        );
        $git = $this->planningGit(commits: [$translationCommit]);
        $github = new InMemoryGitHubRepository(
            closed: [],
            open: [],
            milestones: [new MilestoneInfo(
                7,
                'Next Patch (35)',
                'https://example.test/milestones/7',
            )],
        );

        $plan = (new ReleasePlanner(
            $git,
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.3'), 35, 35, [])),
        ))->plan(
            $this->config(),
            new PlanReleaseInput(
                'stable35',
                null,
                null,
                ReleaseChannel::Final,
                false,
                false,
                ReleaseMode::Normal,
                null,
            ),
        );

        self::assertTrue($plan->ready);
        self::assertSame('15.0.4', $plan->proposedVersion);
        self::assertSame('releasable-activity', $plan->bumpReason);
        self::assertCount(1, $plan->activity);
        self::assertSame('translation', $plan->activity[0]['kind']);
    }

    public function testFailedPublicationRemainsExplicitlyUnverifiedAndRerunnable(): void
    {
        [, $preparation] = $this->planAndPrepare();
        [$prepared, $draft] = $this->finalizeAndDraft($preparation);

        $artifact = $this->artifact($prepared->version, $prepared->changelogSection);
        try {
            $bytes = (string) file_get_contents($artifact);
            $release = new PublishedRelease(
                $draft->releaseId,
                $draft->releaseUrl,
                $prepared->tagName,
                $prepared->finalSha,
                false,
                $draft->prerelease,
                '2026-09-21T18:00:00Z',
                [new PublishedAsset(
                    303,
                    'libresign-' . $prepared->tagName . '.tar.gz',
                    'asset',
                    hash('sha256', $bytes),
                )],
            );
            $failedRun = new PublisherRun(
                202,
                'https://example.test/actions/runs/202',
                $prepared->finalSha,
                'release',
                'completed',
                'failure',
                '2026-09-21T18:01:00Z',
            );

            $verifier = new PublicationVerifier(
                new InMemoryPublicationRepository($release, $failedRun, $bytes),
                new StaticAppStoreRepository(false),
                new ArtifactValidator(new PharArchiveReaderFactory()),
            );

            $first = $verifier->verify(
                $this->config(),
                $draft,
                $prepared,
                new DateTimeImmutable('2026-09-21T19:00:00Z'),
            );
            $second = $verifier->verify(
                $this->config(),
                $draft,
                $prepared,
                new DateTimeImmutable('2026-09-21T19:05:00Z'),
            );

            self::assertFalse($first->success);
            self::assertFalse($first->publisherSucceeded);
            self::assertFalse($first->appStoreVisible);
            self::assertSame($first->id, $second->id);
        } finally {
            @unlink($artifact);
            @rmdir(dirname($artifact));
        }
    }

    public function testStalePreparationFailsClosedAndFreshPlanChangesIdentity(): void
    {
        [$plan] = $this->planAndPrepare(prepare: false);

        $staleGit = $this->planningGit('6666666666666666666666666666666666666666');

        try {
            (new ReleasePreparer($staleGit))->prepare($this->config(), $plan);
            self::fail('Expected stale plan to fail closed.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('ReleasePlan is stale', $exception->getMessage());
        }

        $freshHead = '6666666666666666666666666666666666666666';
        $freshPlan = (new ReleasePlanner(
            $this->planningGit($freshHead),
            $this->planningGitHub(),
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.3'), 35, 35, [])),
        ))->plan(
            $this->config(),
            new PlanReleaseInput(
                'stable35',
                null,
                null,
                ReleaseChannel::Final,
                false,
                false,
                ReleaseMode::Normal,
                null,
            ),
        );

        self::assertNotSame($plan->id, $freshPlan->id);
        self::assertSame($freshHead, $freshPlan->planningBaseSha);
        self::assertTrue($freshPlan->ready);
    }

    /**
     * @param list<PullRequestInfo> $openPullRequests
     * @return array{ReleasePlan, ReleasePreparation|null}
     */
    private function planAndPrepare(
        ReleaseChannel $channel = ReleaseChannel::Final,
        ReleaseMode $mode = ReleaseMode::Normal,
        array $openPullRequests = [],
        bool $ignoreOpenBackport = false,
        string $pullRequestTitle = 'fix: correct signature parsing',
        bool $prepare = true,
    ): array {
        $git = $this->planningGit();
        $planner = new ReleasePlanner(
            $git,
            $this->planningGitHub($channel, $openPullRequests, $pullRequestTitle),
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.3'), 35, 35, [])),
        );
        $plan = $planner->plan(
            $this->config(),
            new PlanReleaseInput(
                'stable35',
                null,
                null,
                $channel,
                $ignoreOpenBackport,
                false,
                $mode,
                null,
            ),
        );

        if (!$prepare) {
            return [$plan, null];
        }

        $result = (new ReleasePreparer($git))->prepare($this->config(), $plan);

        return [
            $plan,
            $result->preparation->withPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
            ),
        ];
    }

    /**
     * @param list<PullRequestInfo> $openPullRequests
     */
    private function planningGitHub(
        ReleaseChannel $channel = ReleaseChannel::Final,
        array $openPullRequests = [],
        string $pullRequestTitle = 'fix: correct signature parsing',
    ): InMemoryGitHubRepository {
        $milestoneTitle = $channel === ReleaseChannel::Final
            ? 'Next Patch (35)'
            : 'Next RC (35)';

        return new InMemoryGitHubRepository(
            closed: [new PullRequestInfo(
                10,
                $pullRequestTitle,
                '',
                'stable35',
                self::MERGE,
                '2026-09-20T00:00:00Z',
                'https://example.test/pull/10',
                [],
                'contributor',
            )],
            open: $openPullRequests,
            milestones: [new MilestoneInfo(7, $milestoneTitle, 'https://example.test/milestones/7')],
        );
    }

    /** @param list<CommitInfo> $commits */
    private function planningGit(string $head = self::BASE, array $commits = []): InMemoryGitRepository
    {
        $files = [
            $head . ':docs/changelogs/changelog-15.md' => "# Changelog\n\n## 15.0.3 - 2026-09-01\n\n### Fixed\n\n- Previous.\n",
            $head . ':appinfo/info.xml' => "<info>\n  <version>15.0.3</version>\n</info>\n",
            $head . ':package.json' => "{\n    \"name\": \"example\",\n    \"version\": \"15.0.3\"\n}\n",
            $head . ':package-lock.json' => "{\n    \"name\": \"example\",\n    \"version\": \"15.0.3\",\n    \"packages\": {\n        \"\": {\n            \"version\": \"15.0.3\"\n        }\n    }\n}\n",
        ];

        return new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => $head],
            [
                $head => [self::PREVIOUS, self::MERGE],
                self::MERGE => [self::PREVIOUS],
                self::PREVIOUS => [],
            ],
            new PreviousRelease('v15.0.3', self::PREVIOUS, 'v15.0.3'),
            $files,
            commits: $commits,
            date: '2026-09-21',
        );
    }

    /**
     * @return array{\LibreCode\ReleaseTool\Domain\Release\PreparedRelease, \LibreCode\ReleaseTool\Domain\Release\ReleaseDraft}
     */
    private function finalizeAndDraft(
        ReleasePreparation $preparation,
        ReleaseChannel $channel = ReleaseChannel::Final,
        ReleaseMode $mode = ReleaseMode::Normal,
    ): array {
        $finalFiles = [];
        foreach ($preparation->fileChanges as $change) {
            self::assertNotNull($change->content);
            $finalFiles[self::FINAL . ':' . $change->path] = $change->content;
        }

        $finalFiles[self::MAIN . ':docs/changelogs/changelog-15.md'] =
            "# Changelog\n\n## 15.0.3 - 2026-09-01\n\n### Fixed\n\n- Previous.\n";

        $git = new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => self::FINAL, 'main' => self::MAIN],
            [self::FINAL => [self::BASE, self::MERGE, self::PREVIOUS]],
            new PreviousRelease('v15.0.3', self::PREVIOUS, 'v15.0.3'),
            $finalFiles,
        );
        $changedFiles = array_map(
            static fn ($change): string => $change->path,
            $preparation->fileChanges,
        );
        $finalization = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                $changedFiles,
            ),
            ['stable35' => self::FINAL, 'main' => self::MAIN],
        );

        $prepared = (new ReleaseFinalizer(
            $git,
            $finalization,
            new StaticMetadataReader(new ReleaseMetadata(
                Version::parse($preparation->version),
                35,
                35,
                [
                    'package.json' => $preparation->version,
                    'package-lock.json' => $preparation->version,
                ],
            )),
        ))->finalize($this->config(), $preparation);

        if ($mode === ReleaseMode::Security) {
            self::assertSame('planned', $prepared->historySynchronization->state->value);
        }

        $milestones = new InMemoryMilestoneRepository([
            new MilestoneInfo(
                7,
                $channel === ReleaseChannel::Final ? 'Next Patch (35)' : 'Next RC (35)',
                'https://example.test/milestones/7',
            ),
        ]);
        $transitioner = new MilestoneTransitioner(
            $milestones,
            new StaticMetadataReader(new ReleaseMetadata(
                Version::parse($preparation->version),
                35,
                35,
                [],
            )),
        );
        $transition = $transitioner->apply(
            $transitioner->plan($this->config(), $prepared, false),
        );

        $draftRepository = new InMemoryReleaseDraftRepository(['stable35' => self::FINAL]);
        $draft = (new ReleaseDrafter($git, $draftRepository))->prepare(
            $this->config(),
            $prepared,
            $transition,
        );

        return [$prepared, $draft];
    }

    private function artifact(string $version, string $changelogSection): string
    {
        $directory = sys_get_temp_dir() . '/release-lifecycle-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $tarPath = $directory . '/libresign-' . $version . '.tar';
        $archive = new PharData($tarPath);
        $archive->addFromString(
            'libresign/appinfo/info.xml',
            '<info><id>libresign</id><version>' . $version . '</version></info>',
        );
        $archive->addFromString(
            'libresign/CHANGELOG.md',
            "# Changelog\n\n" . $changelogSection . "\n",
        );
        $archive->addFromString('libresign/appinfo/routes.php', '<?php');
        $archive->compress(Phar::GZ);
        unset($archive);
        @unlink($tarPath);

        return $tarPath . '.gz';
    }

    private function config(): ConsumerConfig
    {
        return new ConsumerConfig(
            1,
            'libresign',
            'main',
            'LibreSign/libresign',
            '^stable(?<nextcloud>\\d+)$',
            'appinfo/info.xml',
            ['package.json', 'package-lock.json'],
            'v',
            'reachable-tag',
            null,
            'per-major',
            'docs/changelogs/changelog-{major}.md',
            'CHANGELOG.md',
            'Next Patch ({nextcloud})',
            'Next RC ({nextcloud})',
            'maintain',
            'maintain',
            ['make', 'appstore'],
            ['appinfo'],
            ['tests'],
            'appstore-build-publish.yml',
            '{app}-{tag}.tar.gz',
            'https://apps.nextcloud.com/api/v1/apps.json',
        );
    }
}
