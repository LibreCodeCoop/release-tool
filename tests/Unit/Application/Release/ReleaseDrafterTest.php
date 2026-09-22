<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseDraftInfo;
use LibreCode\ReleaseTool\Application\Release\ReleaseDrafter;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Domain\Release\MilestoneTransition;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryReleaseDraftRepository;
use PHPUnit\Framework\TestCase;

final class ReleaseDrafterTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCreatesFinalDraftFromFinalizedArtifacts(): void
    {
        $github = new InMemoryReleaseDraftRepository(['stable35' => self::SHA]);
        $prepared = $this->prepared();
        $draft = (new ReleaseDrafter($this->git($prepared), $github))->prepare(
            $this->config(),
            $prepared,
            $this->milestone(),
        );

        self::assertFalse($draft->prerelease);
        self::assertTrue($draft->draft);
        self::assertTrue($draft->ready);
        self::assertSame(1, $github->createCalls);
        self::assertNotNull($github->release);
        self::assertStringContainsString(
            'Milestone: [v15.0.4](https://example.test/milestones/7?closed=1)',
            $github->release->body,
        );
        self::assertStringEndsWith(
            '**Full Changelog**: https://github.com/LibreSign/libresign/compare/v15.0.3...v15.0.4',
            $github->release->body,
        );
    }

    public function testPrereleaseSetsGitHubPrereleaseFlag(): void
    {
        $prepared = $this->prepared('15.0.4-rc.1', ReleaseChannel::Rc);
        $github = new InMemoryReleaseDraftRepository(['stable35' => self::SHA]);
        $draft = (new ReleaseDrafter($this->git($prepared), $github))->prepare(
            $this->config(),
            $prepared,
            $this->milestone('15.0.4-rc.1'),
        );
        self::assertTrue($draft->prerelease);
    }

    public function testExistingDraftIsUpdatedIdempotently(): void
    {
        $prepared = $this->prepared();
        $existing = new ReleaseDraftInfo(
            101,
            'https://example.test/releases/101',
            'v15.0.4',
            self::SHA,
            'old body',
            true,
            false,
        );
        $github = new InMemoryReleaseDraftRepository(['stable35' => self::SHA], release: $existing);
        $draft = (new ReleaseDrafter($this->git($prepared), $github))->prepare(
            $this->config(),
            $prepared,
            $this->milestone(),
        );
        self::assertSame(0, $github->createCalls);
        self::assertSame(1, $github->updateCalls);
        self::assertSame(101, $draft->releaseId);
    }

    public function testPublishedReleaseIsNeverModified(): void
    {
        $prepared = $this->prepared();
        $published = new ReleaseDraftInfo(101, 'url', 'v15.0.4', self::SHA, 'body', false, false);
        $github = new InMemoryReleaseDraftRepository(['stable35' => self::SHA], release: $published);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already published');
        (new ReleaseDrafter($this->git($prepared), $github))->prepare($this->config(), $prepared, $this->milestone());
    }

    public function testTagPointingElsewhereFailsClosed(): void
    {
        $prepared = $this->prepared();
        $github = new InMemoryReleaseDraftRepository(
            ['stable35' => self::SHA],
            tagTarget: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Existing tag');
        (new ReleaseDrafter($this->git($prepared), $github))->prepare($this->config(), $prepared, $this->milestone());
    }

    public function testInsufficientMergerPermissionFailsClosed(): void
    {
        $prepared = $this->prepared();
        $github = new InMemoryReleaseDraftRepository(
            ['stable35' => self::SHA],
            permissions: ['maintainer' => 'write'],
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('maintain is required');
        (new ReleaseDrafter($this->git($prepared), $github))->prepare($this->config(), $prepared, $this->milestone());
    }

    public function testAllowsBranchAdvanceWhenFinalizedReleaseRemainsAncestor(): void
    {
        $prepared = $this->prepared();
        $advanced = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $github = new InMemoryReleaseDraftRepository(
            ['stable35' => $advanced],
        );
        $draft = (new ReleaseDrafter($this->git($prepared, $advanced), $github))->prepare(
            $this->config(),
            $prepared,
            $this->milestone(),
        );

        self::assertSame('v15.0.4', $draft->tagName);
    }

    public function testRejectsBranchAdvanceWhenFinalizedReleaseIsNotAncestor(): void
    {
        $prepared = $this->prepared();
        $github = new InMemoryReleaseDraftRepository(
            ['stable35' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no longer contains finalized release');
        (new ReleaseDrafter($this->git($prepared), $github))->prepare($this->config(), $prepared, $this->milestone());
    }

    public function testDraftNormalizesCategorySpacing(): void
    {
        $prepared = $this->prepared(section: "## 15.0.4\n\n### Security\n\n- Security fixes.");
        $github = new InMemoryReleaseDraftRepository(['stable35' => self::SHA]);

        (new ReleaseDrafter($this->git($prepared), $github))->prepare(
            $this->config(),
            $prepared,
            $this->milestone(),
        );

        self::assertNotNull($github->release);
        self::assertStringContainsString("### Security\n- Security fixes.", $github->release->body);
        self::assertStringNotContainsString("### Security\n\n- Security fixes.", $github->release->body);
    }

    public function testSecurityDraftUsesOnlyFinalizedPublicChangelogText(): void
    {
        $prepared = $this->prepared(mode: ReleaseMode::Security, section: "## 15.0.4\n\n- Security fixes.");
        $github = new InMemoryReleaseDraftRepository(['stable35' => self::SHA]);
        (new ReleaseDrafter($this->git($prepared), $github))->prepare($this->config(), $prepared, $this->milestone());
        self::assertNotNull($github->release);
        self::assertStringContainsString('Security fixes.', $github->release->body);
        self::assertStringNotContainsString('PRIVATE-ADVISORY', $github->release->body);
    }

    private function git(PreparedRelease $prepared, ?string $branchHead = null): InMemoryGitRepository
    {
        $branchHead ??= self::SHA;
        $files = [];
        foreach ($prepared->releaseFileDigests as $path => $digest) {
            $files[self::SHA . ':' . $path] = $path === 'appinfo/info.xml' ? '<info />' : $path;
        }
        $ancestors = [self::SHA => []];
        if ($branchHead !== self::SHA) {
            $ancestors[$branchHead] = [self::SHA];
        }

        return new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => $branchHead],
            $ancestors,
            new PreviousRelease('v15.0.3', self::SHA, 'v15.0.3'),
            $files,
        );
    }

    private function prepared(
        string $version = '15.0.4',
        ReleaseChannel $channel = ReleaseChannel::Final,
        ReleaseMode $mode = ReleaseMode::Normal,
        string $section = "## 15.0.4\n\n- Fixed release.",
    ): PreparedRelease {
        $files = [
            'appinfo/info.xml' => hash('sha256', '<info />'),
            'package.json' => hash('sha256', 'package.json'),
            'package-lock.json' => hash('sha256', 'package-lock.json'),
            'docs/changelogs/changelog-15.md' => hash('sha256', 'docs/changelogs/changelog-15.md'),
        ];
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
            $mode,
            'docs/changelogs/changelog-15.md',
            $section,
            hash('sha256', $section),
            $files,
            new HistorySynchronization(
                HistorySyncState::AlreadySynchronized,
                'main',
                'docs/changelogs/changelog-15.md',
            ),
        );
    }

    private function milestone(string $version = '15.0.4'): MilestoneTransition
    {
        return new MilestoneTransition(
            'milestone-id',
            'prepared-id',
            7,
            'https://example.test/milestones/7',
            $version,
            8,
            'https://example.test/milestones/8',
            2,
            1,
        );
    }

    private function config(): ConsumerConfig
    {
        return new ConsumerConfig(
            1, 'libresign', 'main', 'LibreSign/libresign', '^stable(?<nextcloud>\\d+)$',
            'appinfo/info.xml', ['package.json', 'package-lock.json'], 'v', 'reachable-tag', null,
            'per-major', 'docs/changelogs/changelog-{major}.md', 'CHANGELOG.md',
            'Next Patch ({nextcloud})', 'Next RC ({nextcloud})', 'maintain', 'maintain',
            ['make', 'appstore', 'verify-appstore-package'],
        );
    }
}
