<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\MilestoneTransitioner;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneWorkItem;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryMilestoneRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\StaticMetadataReader;
use PHPUnit\Framework\TestCase;

final class MilestoneTransitionerTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testPlansAndAppliesStableTransitionWithFollowUp(): void
    {
        $repo = new InMemoryMilestoneRepository(
            [new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/milestones/7')],
            [],
            [7 => [
                new MilestoneWorkItem(10, false, 'https://example.test/issues/10'),
                new MilestoneWorkItem(11, true, 'https://example.test/pull/11'),
            ]],
        );
        $service = $this->service($repo, '15.0.4', 35, 35);

        $plan = $service->plan($this->config(), $this->prepared('15.0.4', ReleaseChannel::Final), true);

        self::assertSame(['rename_milestone','create_follow_up','move_issue','move_pull_request','close_milestone'], array_map(
            static fn ($operation): string => $operation->type,
            $plan->operations,
        ));

        $result = $service->apply($plan);
        self::assertSame('15.0.4', $result->finalTitle);
        self::assertSame(1, $result->movedIssues);
        self::assertSame(1, $result->movedPullRequests);
        self::assertNotNull($result->followUpMilestoneNumber);
        self::assertSame('15.0.4', $repo->closed[0]->title);
        self::assertSame('Next Patch (35)', $repo->open[0]->title);
    }

    public function testFinalStableCanCloseWithoutFollowUp(): void
    {
        $repo = new InMemoryMilestoneRepository(
            [new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/milestones/7')],
        );
        $service = $this->service($repo, '15.0.4', 35, 35);
        $plan = $service->plan($this->config(), $this->prepared('15.0.4', ReleaseChannel::Final), false);

        self::assertNull($plan->followUpTitle);
        self::assertSame(['rename_milestone','close_milestone'], array_map(
            static fn ($operation): string => $operation->type,
            $plan->operations,
        ));
        $result = $service->apply($plan);
        self::assertNull($result->followUpMilestoneNumber);
    }

    public function testPrereleaseUsesConfiguredRcMilestone(): void
    {
        $repo = new InMemoryMilestoneRepository(
            [new MilestoneInfo(8, 'Next RC (35)', 'https://example.test/milestones/8')],
        );
        $service = $this->service($repo, '15.0.4-rc.1', 35, 35);
        $plan = $service->plan($this->config(), $this->prepared('15.0.4-rc.1', ReleaseChannel::Rc), true);

        self::assertSame('Next RC (35)', $plan->currentTitle);
        self::assertSame('15.0.4-rc.1', $plan->finalTitle);
        self::assertSame('Next RC (35)', $plan->followUpTitle);
    }

    public function testRerunRecognizesCompletedTransition(): void
    {
        $repo = new InMemoryMilestoneRepository(
            [new MilestoneInfo(9, 'Next Patch (35)', 'https://example.test/milestones/9')],
            [new MilestoneInfo(7, '15.0.4', 'https://example.test/milestones/7')],
        );
        $service = $this->service($repo, '15.0.4', 35, 35);
        $plan = $service->plan($this->config(), $this->prepared('15.0.4', ReleaseChannel::Final), true);

        self::assertTrue($plan->alreadyApplied);
        self::assertSame([], $plan->operations);
        self::assertTrue($service->apply($plan)->alreadyApplied);
    }

    private function service(InMemoryMilestoneRepository $repo, string $version, int $min, int $max): MilestoneTransitioner
    {
        return new MilestoneTransitioner(
            $repo,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse($version), $min, $max, [])),
        );
    }

    private function prepared(string $version, ReleaseChannel $channel): PreparedRelease
    {
        return new PreparedRelease(
            'prepared-id',
            'plan-id',
            'preparation-id',
            'LibreSign/libresign',
            'stable35',
            77,
            'https://github.com/LibreSign/libresign/pull/77',
            self::SHA,
            $version,
            'v' . $version,
            $channel,
            ReleaseMode::Normal,
            'docs/changelogs/changelog-15.md',
            'section',
            str_repeat('a', 64),
            [],
            new HistorySynchronization(
                HistorySyncState::NotRequired,
                'main',
                'docs/changelogs/changelog-15.md',
            ),
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
            ['package.json','package-lock.json'],
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
            ['make','appstore','verify-appstore-package'],
        );
    }
}
