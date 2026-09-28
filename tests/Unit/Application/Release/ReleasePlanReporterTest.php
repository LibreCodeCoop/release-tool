<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\ReleasePlanReporter;
use LibreCode\ReleaseTool\Domain\Release\BackportBlocker;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Security\PublicReleaseText;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleasePlanReporterTest extends TestCase
{
    #[DataProvider('annotationProvider')]
    public function testGithubAnnotationsDescribeReadiness(
        bool $ready,
        ?array $milestone,
        array $blockers,
        array $warnings,
        array $expected,
        array $unexpected = [],
    ): void {
        $lines = (new ReleasePlanReporter())->githubAnnotations(
            $this->plan($ready, $milestone, $blockers, $warnings),
        );
        $text = implode("\n", $lines);

        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, $text);
        }
        foreach ($unexpected as $fragment) {
            self::assertStringNotContainsString($fragment, $text);
        }
    }

    /** @return iterable<string, array{bool,?array,list<BackportBlocker>,list<string>,list<string>,list<string>}> */
    public static function annotationProvider(): iterable
    {
        yield 'ready plan emits no annotations' => [
            true,
            ['number' => 150, 'title' => '💚 Next Patch (35)', 'url' => 'https://example.test/m150'],
            [],
            [],
            [],
            ['Release plan is not ready'],
        ];

        yield 'missing milestone explains blocker' => [
            false,
            null,
            [],
            ['Expected open milestone not found: Next Patch (35)'],
            [
                'Release plan is not ready (milestone=missing, backport-blockers=0).',
                'Expected open milestone not found: Next Patch (35)',
            ],
            [],
        ];

        yield 'single backport is identified' => [
            false,
            ['number' => 150, 'title' => '💚 Next Patch (35)', 'url' => 'https://example.test/m150'],
            [new BackportBlocker(9001, '[stable35] backport: pending fix', 'https://example.test/9001')],
            ['1 open backport pull request(s) block the selected stable line.'],
            [
                'milestone=💚 Next Patch (35) (#150)',
                'backport-blockers=1',
                'Open backport blocker #9001: [stable35] backport: pending fix',
            ],
            [],
        ];

        yield 'multiple blockers and warnings are all visible' => [
            false,
            ['number' => 150, 'title' => '💚 Next Patch (35)', 'url' => 'https://example.test/m150'],
            [
                new BackportBlocker(9001, 'backport one', 'https://example.test/9001'),
                new BackportBlocker(9002, 'backport two', 'https://example.test/9002'),
            ],
            ['first warning', 'second warning'],
            [
                'backport-blockers=2',
                'Open backport blocker #9001: backport one',
                'Open backport blocker #9002: backport two',
                'first warning',
                'second warning',
            ],
            [],
        ];
    }

    public function testSummaryContainsResolvedMilestoneWarningAndVersion(): void
    {
        $plan = $this->plan(
            true,
            ['number' => 150, 'title' => '💚 Next Patch (35)', 'url' => 'https://example.test/m150'],
            [],
            ['Matched decorated milestone title "💚 Next Patch (35)" to configured milestone "Next Patch (35)".'],
        );

        $summary = (new ReleasePlanReporter())->summary($plan, '0.11.9');

        self::assertStringContainsString('Tool: release-tool 0.11.9', $summary);
        self::assertStringContainsString('Version: `15.0.4` -> `15.0.5`', $summary);
        self::assertStringContainsString('Ready: **yes**', $summary);
        self::assertStringContainsString('Matched decorated milestone title', $summary);
    }

    /**
     * @param ?array{number:int,title:string,url:string} $milestone
     * @param list<BackportBlocker> $blockers
     * @param list<string> $warnings
     */
    private function plan(bool $ready, ?array $milestone, array $blockers, array $warnings): ReleasePlan
    {
        return new ReleasePlan(
            'plan-id',
            'LibreSign/libresign',
            'libresign',
            'stable35',
            35,
            15,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'v15.0.4',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'v15.0.4',
            '15.0.4',
            '15.0.5',
            null,
            ReleaseChannel::Final,
            'releasable-activity',
            [],
            'docs/changelogs/changelog-15.md',
            $milestone,
            $blockers,
            false,
            true,
            ReleaseMode::Normal,
            new PublicReleaseText('Public release', false),
            $warnings,
            $ready,
        );
    }
}
