<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\ReadModel\FinalizedPullRequest;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PreviousRelease;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Application\Release\ReleaseFinalizer;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\FileChange;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryGitRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryReleaseFinalizationRepository;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\StaticMetadataReader;
use PHPUnit\Framework\TestCase;

final class ReleaseFinalizerTest extends TestCase
{
    private const BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FINAL = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const MAIN = 'cccccccccccccccccccccccccccccccccccccccc';

    public function testBuildsPreparedReleaseFromActualMergedStateAndPlansHistorySync(): void
    {
        $git = $this->git();
        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                [
                    'appinfo/info.xml',
                    'docs/changelogs/changelog-15.md',
                    'package-lock.json',
                    'package.json',
                ],
            ),
            ['stable35' => self::FINAL, 'main' => self::MAIN],
        );

        $prepared = (new ReleaseFinalizer(
            $git,
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.4'), 35, 35, [
                'package.json' => '15.0.4',
                'package-lock.json' => '15.0.4',
            ])),
        ))->finalize($this->config(), $this->preparation());

        self::assertSame(self::FINAL, $prepared->finalSha);
        self::assertSame('v15.0.4', $prepared->tagName);
        self::assertSame(HistorySyncState::Planned, $prepared->historySynchronization->state);
        self::assertSame('main', $prepared->historySynchronization->targetBranch);
        self::assertSame('docs/changelogs/changelog-15.md', $prepared->historySynchronization->targetPath);
        self::assertStringContainsString('Human-edited wording', $prepared->changelogSection);
        self::assertSame(
            hash('sha256', $git->readFile(self::FINAL, 'package.json')),
            $prepared->releaseFileDigests['package.json'],
        );
    }

    public function testFallsBackToGitHubWhenHistoryTargetIsNotFetchedLocally(): void
    {
        $files = [
            self::FINAL . ':appinfo/info.xml' => "<info><version>15.0.4</version></info>\n",
            self::FINAL . ':package.json' => "{\"version\":\"15.0.4\",\"human\":true}\n",
            self::FINAL . ':package-lock.json' => "{\"version\":\"15.0.4\"}\n",
            self::FINAL . ':docs/changelogs/changelog-15.md' => "# Changelog\n\n## 15.0.4 - 2026-09-21\n\n### Fixed\n- Human-edited wording (#77)\n\n## 15.0.3 - 2026-09-20\n- Previous.\n",
        ];
        $git = new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => self::FINAL],
            [self::FINAL => [self::BASE]],
            new PreviousRelease('v15.0.3', self::BASE, 'v15.0.3'),
            $files,
        );
        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                array_map(static fn (FileChange $change): string => $change->path, $this->preparation()->fileChanges),
            ),
            ['stable35' => self::FINAL, 'main' => self::MAIN],
            files: [
                self::MAIN . ':docs/changelogs/changelog-15.md' => "# Changelog\n\n## 15.0.3 - 2026-09-20\n- Previous.\n",
            ],
        );

        $prepared = (new ReleaseFinalizer(
            $git,
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.4'), 35, 35, [
                'package.json' => '15.0.4',
                'package-lock.json' => '15.0.4',
            ])),
        ))->finalize($this->config(), $this->preparation());

        self::assertSame(HistorySyncState::Planned, $prepared->historySynchronization->state);
        self::assertSame('main', $prepared->historySynchronization->targetBranch);
    }

    public function testRejectsUnexpectedMergedFile(): void
    {
        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                ['appinfo/info.xml', 'src/Unexpected.php'],
            ),
            ['stable35' => self::FINAL, 'main' => self::MAIN],
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('unexpected files');

        (new ReleaseFinalizer(
            $this->git(),
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.4'), 35, 35, [])),
        ))->finalize($this->config(), $this->preparation());
    }

    public function testRejectsBranchAdvanceAfterMerge(): void
    {
        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                array_map(static fn (FileChange $change): string => $change->path, $this->preparation()->fileChanges),
            ),
            ['stable35' => 'dddddddddddddddddddddddddddddddddddddddd', 'main' => self::MAIN],
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no longer contains merged release');

        (new ReleaseFinalizer(
            $this->git(),
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.4'), 35, 35, [])),
        ))->finalize($this->config(), $this->preparation());
    }

    public function testAllowsDelayedFinalizationWhenMergedReleaseRemainsAncestor(): void
    {
        $advanced = 'dddddddddddddddddddddddddddddddddddddddd';
        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                array_map(static fn (FileChange $change): string => $change->path, $this->preparation()->fileChanges),
            ),
            ['stable35' => $advanced, 'main' => self::MAIN],
        );

        $prepared = (new ReleaseFinalizer(
            $this->git($advanced),
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.4'), 35, 35, [
                'package.json' => '15.0.4',
                'package-lock.json' => '15.0.4',
            ])),
        ))->finalize($this->config(), $this->preparation());

        self::assertSame(self::FINAL, $prepared->finalSha);
        self::assertSame('v15.0.4', $prepared->tagName);
    }

    public function testSecurityModeSynchronizesPublicSafeHistory(): void
    {
        $preparation = $this->preparation(ReleaseMode::Security);
        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                77,
                'https://github.com/LibreSign/libresign/pull/77',
                'stable35',
                true,
                self::FINAL,
                array_map(static fn (FileChange $change): string => $change->path, $preparation->fileChanges),
            ),
            ['stable35' => self::FINAL, 'main' => self::MAIN],
            new HistorySynchronization(
                HistorySyncState::PullRequestOpen,
                'main',
                'docs/changelogs/changelog-15.md',
                'release-tool/history/15.0.4/main/test',
                88,
                'https://github.com/LibreSign/libresign/pull/88',
            ),
        );

        $prepared = (new ReleaseFinalizer(
            $this->git(),
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('15.0.4'), 35, 35, [])),
        ))->finalize($this->config(), $preparation, true);

        self::assertSame(HistorySyncState::PullRequestOpen, $prepared->historySynchronization->state);
        self::assertNotNull($github->lastHistoryRequest);
        self::assertSame('main', $github->lastHistoryRequest->targetBranch);
        self::assertStringContainsString('### Fixed', $github->lastHistoryRequest->content);
    }

    public function testCascadesHistoryThroughNewerStableBranchesAndMain(): void
    {
        $stable34 = '3434343434343434343434343434343434343434';
        $stable35 = '3535353535353535353535353535353535353535';
        $main = self::MAIN;
        $section = "## 13.4.2 - 2026-09-21\n\n### Security\n- Security fixes and other improvements";

        $changes = [];
        foreach ([
            'appinfo/info.xml',
            'docs/changelogs/changelog-13.md',
            'package-lock.json',
            'package.json',
        ] as $path) {
            $changes[] = new FileChange($path, str_repeat('1', 64), str_repeat('2', 64), null);
        }
        $preparation = new ReleasePreparation(
            'preparation-13',
            'plan-13',
            'LibreSign/libresign',
            'stable33',
            self::BASE,
            '13.4.2',
            ReleaseChannel::Final,
            ReleaseMode::Security,
            $changes,
            $section,
            hash('sha256', $section),
            'release-tool/stable33/13.4.2/preparation',
            '<!-- release-tool:preparation plan=plan-13 version=13.4.2 -->',
            99,
            'https://github.com/LibreSign/libresign/pull/99',
        );

        $files = [
            self::FINAL . ':appinfo/info.xml' => "<info><version>13.4.2</version></info>\n",
            self::FINAL . ':package.json' => "{\"version\":\"13.4.2\"}\n",
            self::FINAL . ':package-lock.json' => "{\"version\":\"13.4.2\"}\n",
            self::FINAL . ':docs/changelogs/changelog-13.md' => "# Changelog\n\n" . $section . "\n\n## 13.4.1 - 2026-09-20\n- Previous.\n",
            $stable34 . ':docs/changelogs/changelog-13.md' => "# Changelog\n\n## 13.4.1 - 2026-09-20\n- Previous.\n",
            $stable35 . ':docs/changelogs/changelog-13.md' => "# Changelog\n\n## 13.4.1 - 2026-09-20\n- Previous.\n",
            $main . ':docs/changelogs/changelog-13.md' => "# Changelog\n\n## 13.4.1 - 2026-09-20\n- Previous.\n",
        ];
        $git = new InMemoryGitRepository(
            'LibreSign/libresign',
            [
                'stable33' => self::FINAL,
                'stable34' => $stable34,
                'stable35' => $stable35,
                'main' => $main,
            ],
            [self::FINAL => [self::BASE]],
            new PreviousRelease('v13.4.1', self::BASE, 'v13.4.1'),
            $files,
        );

        $github = new InMemoryReleaseFinalizationRepository(
            new FinalizedPullRequest(
                99,
                'https://github.com/LibreSign/libresign/pull/99',
                'stable33',
                true,
                self::FINAL,
                array_map(static fn (FileChange $change): string => $change->path, $changes),
            ),
            [
                'stable33' => self::FINAL,
                'stable34' => $stable34,
                'stable35' => $stable35,
                'main' => $main,
            ],
            new HistorySynchronization(
                HistorySyncState::PullRequestOpen,
                'main',
                'docs/changelogs/changelog-13.md',
                'release-tool/history/13.4.2/main/test',
                100,
                'https://github.com/LibreSign/libresign/pull/100',
            ),
        );

        (new ReleaseFinalizer(
            $git,
            $github,
            new StaticMetadataReader(new ReleaseMetadata(Version::parse('13.4.2'), 33, 33, [
                'package.json' => '13.4.2',
                'package-lock.json' => '13.4.2',
            ])),
        ))->finalize($this->config(), $preparation, true);

        self::assertSame(
            ['stable34', 'stable35', 'main'],
            array_map(static fn ($request): string => $request->targetBranch, $github->historyRequests),
        );
        foreach ($github->historyRequests as $request) {
            self::assertStringContainsString($section, $request->content);
        }
    }

    private function git(?string $branchHead = null): InMemoryGitRepository
    {
        $branchHead ??= self::FINAL;
        $files = [
            self::FINAL . ':appinfo/info.xml' => "<info><version>15.0.4</version></info>\n",
            self::FINAL . ':package.json' => "{\"version\":\"15.0.4\",\"human\":true}\n",
            self::FINAL . ':package-lock.json' => "{\"version\":\"15.0.4\"}\n",
            self::FINAL . ':docs/changelogs/changelog-15.md' => "# Changelog\n\n## 15.0.4 - 2026-09-21\n\n### Fixed\n\n- Human-edited wording (#77)\n\n## 15.0.3 - 2026-09-20\n\n- Previous.\n",
            self::MAIN . ':docs/changelogs/changelog-15.md' => "# Changelog\n\n## 15.0.3 - 2026-09-20\n\n- Previous.\n",
        ];

        $ancestors = [self::FINAL => [self::BASE]];
        if ($branchHead !== self::FINAL) {
            $ancestors[$branchHead] = [self::FINAL, self::BASE];
        }

        return new InMemoryGitRepository(
            'LibreSign/libresign',
            ['stable35' => $branchHead, 'main' => self::MAIN],
            $ancestors,
            new PreviousRelease('v15.0.3', self::BASE, 'v15.0.3'),
            $files,
        );
    }

    private function preparation(ReleaseMode $mode = ReleaseMode::Normal): ReleasePreparation
    {
        $changes = [];
        foreach ([
            'appinfo/info.xml',
            'docs/changelogs/changelog-15.md',
            'package-lock.json',
            'package.json',
        ] as $path) {
            $changes[] = new FileChange($path, str_repeat('1', 64), str_repeat('2', 64), null);
        }

        return new ReleasePreparation(
            'preparation-id',
            'plan-id',
            'LibreSign/libresign',
            'stable35',
            self::BASE,
            '15.0.4',
            ReleaseChannel::Final,
            $mode,
            $changes,
            "## 15.0.4 - 2026-09-21\n\n### Fixed\n\n- Generated wording (#77)",
            str_repeat('3', 64),
            'release-tool/stable35/15.0.4/preparation',
            '<!-- release-tool:preparation plan=plan-id version=15.0.4 -->',
            77,
            'https://github.com/LibreSign/libresign/pull/77',
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
