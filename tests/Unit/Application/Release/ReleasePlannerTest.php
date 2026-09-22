<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\PlanReleaseInput;
use LibreCode\ReleaseTool\Application\Release\ReadModel\CommitInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanner;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitHubRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\StaticMetadataReader;
use PHPUnit\Framework\TestCase;

final class ReleasePlannerTest extends TestCase
{
    private const PREVIOUS = '1111111111111111111111111111111111111111';
    private const MERGE = '2222222222222222222222222222222222222222';
    private const HEAD = '3333333333333333333333333333333333333333';
    private const SOURCE = '4444444444444444444444444444444444444444';

    protected function setUp(): void
    {
        putenv('GITHUB_REPOSITORY');
    }

    public function testFeaturePullRequestProducesMinorPlanAndRealBackportBlocks(): void
    {
        $planner = $this->planner(
            closed: [
                new PullRequestInfo(10, 'feat(sign): add policy', '', 'stable35', self::MERGE, '2026-09-20T00:00:00Z', 'https://example.test/10', [], 'contributor'),
            ],
            open: [
                new PullRequestInfo(20, '[stable35] backport: fix: pending fix', '', 'stable35', null, null, 'https://example.test/20', ['backport'], 'contributor'),
                new PullRequestInfo(21, '[stable34] backport: fix: other line', '', 'stable34', null, null, 'https://example.test/21', ['backport'], 'contributor'),
                new PullRequestInfo(22, 'fix: unrelated open work', '', 'stable35', null, null, 'https://example.test/22', [], 'contributor'),
            ],
            milestones: [new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/m7')],
        );

        $plan = $planner->plan($this->config(), $this->input());

        self::assertSame('15.1.0', $plan->proposedVersion);
        self::assertSame('feature-pr', $plan->bumpReason);
        self::assertCount(1, $plan->backportBlockers);
        self::assertSame(20, $plan->backportBlockers[0]->number);
        self::assertFalse($plan->ready);
        self::assertSame('Next Patch (35)', $plan->milestone['title']);
        self::assertStringContainsString('other open pull request', implode("\n", $plan->warnings));
    }

    public function testBackportAndReleaseToolingDoNotPromoteMinorOrDuplicateCommits(): void
    {
        $planner = $this->planner(
            closed: [
                new PullRequestInfo(
                    10,
                    '[stable35] feat(chat): add visible signatures',
                    '',
                    'stable35',
                    self::MERGE,
                    '2026-09-20T00:00:00Z',
                    'https://example.test/10',
                    ['backport'],
                    'contributor',
                ),
                new PullRequestInfo(
                    11,
                    'feat(release): integrate reusable release tooling',
                    '',
                    'stable35',
                    self::HEAD,
                    '2026-09-20T00:00:00Z',
                    'https://example.test/11',
                    [],
                    'contributor',
                ),
            ],
            milestones: [new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/m7')],
            commits: [
                new CommitInfo(self::SOURCE, 'fix: implementation detail from backport', ['lib/Service.php']),
            ],
            pullRequestCommits: [
                10 => [self::SOURCE],
            ],
        );

        $plan = $planner->plan($this->config(), $this->input());

        self::assertSame('15.0.4', $plan->proposedVersion);
        self::assertSame('releasable-activity', $plan->bumpReason);
        self::assertCount(2, $plan->activity);
        self::assertTrue($plan->activity[0]['backport']);
        self::assertFalse($plan->activity[0]['maintenance']);
        self::assertSame('https://example.test/10', $plan->activity[0]['url']);
        self::assertFalse($plan->activity[1]['backport']);
        self::assertTrue($plan->activity[1]['maintenance']);
        self::assertSame([10, 11], array_column($plan->activity, 'pull_request'));
    }

    public function testDirectCommitsAreIgnoredExceptForTranslationDetection(): void
    {
        $planner = $this->planner(
            closed: [
                new PullRequestInfo(
                    10,
                    'fix: correct signature parsing',
                    '',
                    'stable35',
                    self::MERGE,
                    '2026-09-20T00:00:00Z',
                    'https://example.test/10',
                    [],
                    'contributor',
                ),
            ],
            milestones: [new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/m7')],
            commits: [
                new CommitInfo(self::SOURCE, 'fix: direct implementation detail', ['lib/Service.php']),
                new CommitInfo(
                    '5555555555555555555555555555555555555555',
                    'chore(l10n): update translations',
                    ['l10n/pt_BR.js', 'l10n/pt_BR.json'],
                ),
            ],
        );

        $plan = $planner->plan($this->config(), $this->input());

        self::assertSame('15.0.4', $plan->proposedVersion);
        self::assertCount(2, $plan->activity);
        self::assertSame(10, $plan->activity[0]['pull_request']);
        self::assertSame('translation', $plan->activity[1]['kind']);
        self::assertSame('Update translations', $plan->activity[1]['title']);
        self::assertSame(
            ['pull_request', 'translation'],
            array_column($plan->activity, 'kind'),
        );
    }

    public function testExplicitBackportOverrideIsAuditableAndMakesPlanReady(): void
    {
        $planner = $this->planner(
            closed: [new PullRequestInfo(10, 'fix: patch', '', 'stable35', self::MERGE, '2026-09-20T00:00:00Z', 'https://example.test/10', [], 'contributor')],
            open: [new PullRequestInfo(20, 'backport: fix: pending', '', 'stable35', null, null, 'https://example.test/20', [], 'contributor')],
            milestones: [new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/m7')],
        );

        $plan = $planner->plan($this->config(), $this->input(ignoreBackport: true));

        self::assertSame('15.0.4', $plan->proposedVersion);
        self::assertTrue($plan->ignoreOpenBackport);
        self::assertTrue($plan->ready);
        self::assertCount(1, $plan->backportBlockers);
        self::assertStringContainsString('Explicit override', implode("\n", $plan->warnings));
    }

    public function testMissingExpectedMilestoneProducesNotReadyPlan(): void
    {
        $planner = $this->planner(
            closed: [new PullRequestInfo(10, 'fix: patch', '', 'stable35', self::MERGE, '2026-09-20T00:00:00Z', 'https://example.test/10', [], 'contributor')],
        );

        $plan = $planner->plan($this->config(), $this->input());

        self::assertFalse($plan->ready);
        self::assertNull($plan->milestone);
        self::assertStringContainsString('Expected open milestone not found', implode("\n", $plan->warnings));
    }

    private function planner(
        array $closed = [],
        array $open = [],
        array $milestones = [],
        array $commits = [],
        array $pullRequestCommits = [],
    ): ReleasePlanner {
        $git = new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => self::HEAD],
            [
                self::HEAD => [self::PREVIOUS, self::MERGE],
                self::MERGE => [self::PREVIOUS],
                self::PREVIOUS => [],
            ],
            new PreviousRelease('v15.0.3', self::PREVIOUS, 'v15.0.3'),
            [self::HEAD . ':docs/changelogs/changelog-15.md' => "# Changelog\n"],
            $commits,
        );

        return new ReleasePlanner(
            $git,
            new InMemoryGitHubRepository($closed, $open, $milestones, pullRequestCommits: $pullRequestCommits),
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.3'), 35, 35, [])),
        );
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
            ['make', 'appstore', 'verify-appstore-package'],
        );
    }

    private function input(bool $ignoreBackport = false): PlanReleaseInput
    {
        return new PlanReleaseInput(
            'stable35',
            null,
            null,
            ReleaseChannel::Final,
            $ignoreBackport,
            false,
            ReleaseMode::Normal,
            null,
        );
    }
}
