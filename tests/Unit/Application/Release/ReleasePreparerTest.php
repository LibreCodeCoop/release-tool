<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparer;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Security\PublicReleaseText;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use PHPUnit\Framework\TestCase;

final class ReleasePreparerTest extends TestCase
{
    private const BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testProducesOnlyConfiguredReleaseFilesDeterministically(): void
    {
        $result = (new ReleasePreparer($this->git()))->prepare($this->config(), $this->plan());
        $preparation = $result->preparation;

        self::assertSame('15.0.4', $preparation->version);
        self::assertSame(
            [
                'appinfo/info.xml',
                'docs/changelogs/changelog-15.md',
                'package-lock.json',
                'package.json',
            ],
            array_map(static fn ($change): string => $change->path, $preparation->fileChanges),
        );
        self::assertStringContainsString('## 15.0.4 - 2026-09-20', $preparation->changelogSection);
        self::assertStringContainsString('- correct signature parsing (#10)', $preparation->changelogSection);
        self::assertStringContainsString('--- before', $result->diff);
        self::assertStringContainsString('--- after', $result->diff);
        self::assertSame(
            'release-tool/stable35/15.0.4/plan-1234567',
            $preparation->generatedBranch,
        );

        $rerun = (new ReleasePreparer($this->git()))->prepare($this->config(), $this->plan());
        self::assertSame($preparation->id, $rerun->preparation->id);
        self::assertSame($result->diff, $rerun->diff);
    }

    public function testSecurityModePublishesOnlyApprovedPublicText(): void
    {
        $plan = $this->plan(
            mode: ReleaseMode::Security,
            publicText: new PublicReleaseText('This release includes security fixes.', false),
            activity: [[
                'kind' => 'pull_request',
                'title' => 'fix: PRIVATE vulnerability details',
                'pull_request' => 10,
                'type' => 'fix',
            ]],
        );

        $result = (new ReleasePreparer($this->git()))->prepare($this->config(), $plan);

        self::assertStringContainsString('### Security', $result->preparation->changelogSection);
        self::assertStringContainsString('This release includes security fixes.', $result->preparation->changelogSection);
        self::assertStringNotContainsString('PRIVATE vulnerability details', $result->preparation->changelogSection);
        self::assertStringNotContainsString('PRIVATE vulnerability details', $result->diff);
    }

    public function testRejectsStalePlan(): void
    {
        $git = $this->git(branchHead: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ReleasePlan is stale');

        (new ReleasePreparer($git))->prepare($this->config(), $this->plan());
    }

    public function testRejectsUnexpectedChangelogTargetFromPlan(): void
    {
        $plan = $this->plan(changelogTarget: 'CHANGELOG.md');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not match consumer configuration');

        (new ReleasePreparer($this->git()))->prepare($this->config(), $plan);
    }

    private function git(string $branchHead = self::BASE): InMemoryGitRepository
    {
        $files = [
            self::BASE . ':docs/changelogs/changelog-15.md' => "# Changelog\n\n## 15.0.3 - 2026-09-01\n\n### Fixed\n\n- Previous.\n",
            self::BASE . ':appinfo/info.xml' => "<info>\n  <version>15.0.3</version>\n</info>\n",
            self::BASE . ':package.json' => "{\n    \"name\": \"example\",\n    \"version\": \"15.0.3\"\n}\n",
            self::BASE . ':package-lock.json' => "{\n    \"name\": \"example\",\n    \"version\": \"15.0.3\",\n    \"packages\": {\n        \"\": {\n            \"version\": \"15.0.3\"\n        }\n    }\n}\n",
        ];

        return new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => $branchHead],
            [self::BASE => []],
            new PreviousRelease('v15.0.3', self::BASE, 'v15.0.3'),
            $files,
            date: '2026-09-20',
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

    /** @param list<array<string, mixed>>|null $activity */
    private function plan(
        ReleaseMode $mode = ReleaseMode::Normal,
        ?PublicReleaseText $publicText = null,
        ?array $activity = null,
        string $changelogTarget = 'docs/changelogs/changelog-15.md',
    ): ReleasePlan {
        return new ReleasePlan(
            'plan-1234567890abcdef',
            'LibreSign/libresign',
            'libresign',
            'stable35',
            35,
            15,
            self::BASE,
            'v15.0.3',
            self::BASE,
            'v15.0.3',
            '15.0.3',
            '15.0.4',
            null,
            ReleaseChannel::Final,
            'releasable-activity',
            $activity ?? [[
                'kind' => 'pull_request',
                'title' => 'fix: correct signature parsing',
                'pull_request' => 10,
                'type' => 'fix',
                'url' => 'https://example.test/10',
            ]],
            $changelogTarget,
            ['number' => 7, 'title' => 'Next Patch (35)', 'url' => 'https://example.test/m7'],
            [],
            false,
            false,
            $mode,
            $publicText ?? new PublicReleaseText('Maintenance release.', false),
            [],
            true,
        );
    }
}
