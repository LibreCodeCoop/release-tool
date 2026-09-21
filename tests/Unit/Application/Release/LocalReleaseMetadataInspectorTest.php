<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\LocalReleaseMetadataInspector;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\StaticMetadataReader;
use PHPUnit\Framework\TestCase;

final class LocalReleaseMetadataInspectorTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testDevelopmentVersionSelectsMajorChangelogWithoutReleaseSection(): void
    {
        $git = $this->git('# Changelog');
        $inspector = new LocalReleaseMetadataInspector(
            $git,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('16.0.0-dev.2'), 36, 36, [])),
        );

        $result = $inspector->inspect($this->config(), self::SHA);

        self::assertSame('16.0.0-dev.2', $result->version);
        self::assertSame(16, $result->major);
        self::assertSame('docs/changelogs/changelog-16.md', $result->changelogPath);
        self::assertFalse($result->releaseSectionRequired);
        self::assertFalse($result->releaseSectionPresent);
    }

    public function testFinalVersionRequiresExactReleaseSection(): void
    {
        $git = $this->git("# Changelog\n\n## 16.0.0 - 2026-09-21\n");
        $inspector = new LocalReleaseMetadataInspector(
            $git,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('16.0.0'), 36, 36, [])),
        );

        $result = $inspector->inspect($this->config(), self::SHA);
        self::assertTrue($result->releaseSectionRequired);
        self::assertTrue($result->releaseSectionPresent);
    }

    public function testFinalVersionFailsWithoutReleaseSection(): void
    {
        $git = $this->git("# Changelog\n\n## 15.0.9 - 2026-09-20\n");
        $inspector = new LocalReleaseMetadataInspector(
            $git,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('16.0.0'), 36, 36, [])),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('does not contain version 16.0.0');
        $inspector->inspect($this->config(), self::SHA);
    }

    private function git(string $changelog): InMemoryGitRepository
    {
        return new InMemoryGitRepository(
            'LibreSign/libresign',
            ['HEAD' => self::SHA],
            [self::SHA => []],
            new PreviousRelease(null, self::SHA, self::SHA),
            [self::SHA . ':docs/changelogs/changelog-16.md' => $changelog],
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
}
