<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommand;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanner;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitHubRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\StaticMetadataReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReleasePlanCommandContractTest extends TestCase
{
    private const PREVIOUS = '1111111111111111111111111111111111111111';
    private const MERGE = '2222222222222222222222222222222222222222';
    private const HEAD = '3333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        putenv('GITHUB_REPOSITORY');
    }

    public function testReadyPlanKeepsJsonContractWithDecoratedMilestone(): void
    {
        $tester = $this->tester(
            milestones: [new MilestoneInfo(150, '💚 Next Patch (35)', 'https://example.test/m150')],
        );

        $status = $tester->execute($this->arguments());

        self::assertSame(0, $status);

        $plan = $this->json($tester);
        self::assertSame(1, $plan['schema']);
        self::assertSame('LibreSign/libresign', $plan['repository']);
        self::assertSame('stable35', $plan['branch']);
        self::assertSame('15.0.3', $plan['current_version']);
        self::assertSame('15.0.4', $plan['proposed_version']);
        self::assertTrue($plan['ready']);
        self::assertSame(150, $plan['milestone']['number']);
        self::assertSame('💚 Next Patch (35)', $plan['milestone']['title']);
        self::assertSame([], $plan['backport_blockers']);
        self::assertStringContainsString(
            'Matched decorated milestone title',
            implode("\n", $plan['warnings']),
        );
    }

    public function testMissingMilestoneKeepsPlanValidButNotReady(): void
    {
        $tester = $this->tester(milestones: []);

        $status = $tester->execute($this->arguments());

        self::assertSame(ReleasePlanCommand::EXIT_NOT_READY, $status);

        $plan = $this->json($tester);
        self::assertFalse($plan['ready']);
        self::assertNull($plan['milestone']);
        self::assertStringContainsString(
            'Expected open milestone not found',
            implode("\n", $plan['warnings']),
        );
    }

    public function testBackportBlockerRequiresExplicitOverride(): void
    {
        $backport = new PullRequestInfo(
            20,
            '[stable35] backport: fix: pending fix',
            '',
            'stable35',
            null,
            null,
            'https://example.test/20',
            ['backport'],
            'contributor',
        );

        $blocked = $this->tester(open: [$backport]);
        self::assertSame(
            ReleasePlanCommand::EXIT_NOT_READY,
            $blocked->execute($this->arguments()),
        );

        $blockedPlan = $this->json($blocked);
        self::assertFalse($blockedPlan['ready']);
        self::assertSame(20, $blockedPlan['backport_blockers'][0]['number']);
        self::assertFalse($blockedPlan['ignore_open_backport']);

        $overridden = $this->tester(open: [$backport]);
        self::assertSame(
            0,
            $overridden->execute($this->arguments([
                '--ignore-open-backport' => true,
            ])),
        );

        $overriddenPlan = $this->json($overridden);
        self::assertTrue($overriddenPlan['ready']);
        self::assertTrue($overriddenPlan['ignore_open_backport']);
        self::assertSame(20, $overriddenPlan['backport_blockers'][0]['number']);
    }

    public function testNoReleasableActivityRemainsAnInvalidPlanRequest(): void
    {
        $tester = $this->tester(closed: []);

        $status = $tester->execute($this->arguments());

        self::assertSame(2, $status);

        $error = $this->json($tester);
        self::assertSame(1, $error['schema']);
        self::assertStringContainsString(
            'No releasable activity exists after the previous release.',
            $error['error'],
        );
    }

    public function testConfigurationFailureKeepsJsonErrorContract(): void
    {
        $tester = $this->tester();

        $status = $tester->execute($this->arguments([
            '--config' => '/definitely/missing/release-config.yml',
        ]));

        self::assertSame(2, $status);

        $error = $this->json($tester);
        self::assertSame(1, $error['schema']);
        self::assertStringContainsString('Configuration file not found', $error['error']);
    }

    public function testRepositoryContextFailureKeepsJsonErrorContract(): void
    {
        $validator = new class implements ConsumerConfigContextValidator {
            public function validate(ConsumerConfig $config, string $root): void
            {
                throw new InvalidArgumentException('Repository identity mismatch.');
            }
        };
        $tester = $this->tester(contextValidator: $validator);

        $status = $tester->execute($this->arguments());

        self::assertSame(2, $status);

        $error = $this->json($tester);
        self::assertSame(1, $error['schema']);
        self::assertSame('Repository identity mismatch.', $error['error']);
    }

    /**
     * @param list<PullRequestInfo>|null $closed
     * @param list<PullRequestInfo> $open
     * @param list<MilestoneInfo>|null $milestones
     */
    private function tester(
        ?array $closed = null,
        array $open = [],
        ?array $milestones = null,
        ?ConsumerConfigContextValidator $contextValidator = null,
    ): CommandTester {
        $git = new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => self::HEAD],
            [
                self::HEAD => [self::PREVIOUS, self::MERGE],
                self::MERGE => [self::PREVIOUS],
                self::PREVIOUS => [],
            ],
            new PreviousRelease('v15.0.3', self::PREVIOUS, 'v15.0.3'),
            [
                self::HEAD . ':docs/changelogs/changelog-15.md' => "# Changelog\n",
            ],
        );

        $github = new InMemoryGitHubRepository(
            closed: $closed ?? [
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
            open: $open,
            milestones: $milestones ?? [
                new MilestoneInfo(7, 'Next Patch (35)', 'https://example.test/m7'),
            ],
        );

        $planner = new ReleasePlanner(
            $git,
            $github,
            new StaticMetadataReader(new ReleaseMetadata(
                Version::parse('15.0.3'),
                35,
                35,
                [],
            )),
        );

        return new CommandTester(new ReleasePlanCommand(
            $planner,
            new ConsumerConfigLoader(),
            $contextValidator ?? new NoopConsumerConfigContextValidator(),
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function arguments(array $overrides = []): array
    {
        return array_replace([
            '--branch' => 'stable35',
            '--config' => dirname(__DIR__) . '/Fixtures/Configuration/libresign.yml',
            '--json' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function json(CommandTester $tester): array
    {
        return json_decode(
            $tester->getDisplay(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
