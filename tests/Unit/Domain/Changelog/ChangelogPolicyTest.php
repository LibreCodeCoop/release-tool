<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Changelog;

use DomainException;
use LibreCode\ReleaseTool\Domain\Changelog\ChangelogPolicy;
use LibreCode\ReleaseTool\Domain\Release\ReleaseActivity;
use LibreCode\ReleaseTool\Domain\Release\ReleaseItem;
use LibreCode\ReleaseTool\Domain\Version\Version;
use PHPUnit\Framework\TestCase;

final class ChangelogPolicyTest extends TestCase
{
    public function testRendersCuratedDeterministicKeepAChangelogSection(): void
    {
        $activity = new ReleaseActivity([
            new ReleaseItem('pull_request', 'fix(pdf): avoid invalid signature state', 12, 'fix'),
            new ReleaseItem('dependency', 'chore(deps): bump package A', 13, 'chore'),
            new ReleaseItem('dependency', 'chore(deps): bump package B', 14, 'chore'),
            new ReleaseItem('translation', 'Update translations'),
            new ReleaseItem('pull_request', '[stable35] backport: feat: add visible signatures', 15, 'feat'),
            new ReleaseItem('pull_request', 'security hardening', 16, 'fix', publicSecurityEntry: true),
        ]);

        $result = (new ChangelogPolicy())->prepare(
            Version::parse('15.2.0'),
            $activity,
            'docs/changelogs/changelog-{major}.md',
            "# Changelog\n\n## 15.1.0 - 2026-08-01\n\n### Fixed\n\n- Old fix.\n",
            '2026-09-21',
        );

        self::assertSame('docs/changelogs/changelog-15.md', $result->targetPath);
        self::assertStringContainsString('## 15.2.0 - 2026-09-21', $result->releaseSection);
        self::assertStringContainsString("### Added\n- add visible signatures (#15)", $result->releaseSection);
        self::assertStringContainsString("### Fixed\n- avoid invalid signature state (#12)", $result->releaseSection);
        self::assertSame(1, substr_count($result->releaseSection, 'Dependency updates.'));
        self::assertSame(1, substr_count($result->releaseSection, 'Translation updates.'));
        self::assertStringContainsString("### Security\n- security hardening (#16)", $result->releaseSection);
        self::assertStringContainsString('## 15.1.0', $result->content);
        self::assertStringContainsString("- security hardening (#16)\n\n## 15.1.0", $result->content);
    }

    public function testRejectsDuplicateVersion(): void
    {
        $this->expectException(DomainException::class);

        (new ChangelogPolicy())->prepare(
            Version::parse('15.1.0'),
            new ReleaseActivity([]),
            'changelog-{major}.md',
            "## 15.1.0 - 2026-01-01\n",
        );
    }
}
