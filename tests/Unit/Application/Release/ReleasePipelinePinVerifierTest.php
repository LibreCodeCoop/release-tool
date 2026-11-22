<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\ReleasePipelinePinVerifier;
use PHPUnit\Framework\TestCase;

final class ReleasePipelinePinVerifierTest extends TestCase
{
    private const CANDIDATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const REFERENCE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testAcceptsMatchingReleasePipelinePins(): void
    {
        $content = <<<'YAML'
steps:
  - uses: LibreCodeCoop/release-tool/actions/prepare@1111111111111111111111111111111111111111
  - uses: actions/checkout@2222222222222222222222222222222222222222
YAML;

        $verifier = new ReleasePipelinePinVerifier($this->git([
            self::CANDIDATE . ':workflow.yml' => $content,
            self::REFERENCE . ':workflow.yml' => $content,
        ]));

        self::assertSame([], $verifier->mismatches(
            'HEAD',
            'refs/remotes/origin/main',
            ['workflow.yml'],
            ['LibreCodeCoop/release-tool/actions/'],
        ));
    }

    public function testReportsOnlyRelevantPinDrift(): void
    {
        $candidate = <<<'YAML'
steps:
  - uses: LibreCodeCoop/release-tool/actions/prepare@1111111111111111111111111111111111111111
  - uses: actions/checkout@3333333333333333333333333333333333333333
YAML;
        $reference = <<<'YAML'
steps:
  - uses: LibreCodeCoop/release-tool/actions/prepare@4444444444444444444444444444444444444444
  - uses: actions/checkout@2222222222222222222222222222222222222222
YAML;

        $verifier = new ReleasePipelinePinVerifier($this->git([
            self::CANDIDATE . ':workflow.yml' => $candidate,
            self::REFERENCE . ':workflow.yml' => $reference,
        ]));

        self::assertSame([
            'workflow.yml' => [
                'candidate' => [
                    'LibreCodeCoop/release-tool/actions/prepare@1111111111111111111111111111111111111111',
                ],
                'reference' => [
                    'LibreCodeCoop/release-tool/actions/prepare@4444444444444444444444444444444444444444',
                ],
            ],
        ], $verifier->mismatches(
            'HEAD',
            'refs/remotes/origin/main',
            ['workflow.yml'],
            ['LibreCodeCoop/release-tool/actions/'],
        ));
    }

    public function testIgnoresOptionalWorkflowAbsentFromBothRevisions(): void
    {
        $verifier = new ReleasePipelinePinVerifier($this->git([
            self::CANDIDATE . ':prepare.yml' => 'on: workflow_dispatch',
            self::REFERENCE . ':prepare.yml' => 'on: workflow_dispatch',
        ]));

        self::assertSame([], $verifier->mismatches(
            'HEAD',
            'refs/remotes/origin/main',
            ['prepare.yml', 'nightly-release.yml', 'release-metadata.yml'],
            ['LibreCodeCoop/release-tool/actions/'],
        ));
    }

    public function testRejectsMissingCandidateWorkflowEvenWithoutTrackedPins(): void
    {
        $verifier = new ReleasePipelinePinVerifier($this->git([
            self::REFERENCE . ':nightly-release.yml' => 'on: workflow_dispatch',
        ]));

        self::assertSame([
            'nightly-release.yml' => ['candidate' => [], 'reference' => []],
        ], $verifier->mismatches(
            'HEAD',
            'refs/remotes/origin/main',
            ['nightly-release.yml'],
            ['LibreCodeCoop/release-tool/actions/'],
        ));
    }

    public function testRejectsUntrustedWorkflowAddedOnlyInCandidate(): void
    {
        $verifier = new ReleasePipelinePinVerifier($this->git([
            self::CANDIDATE . ':nightly-release.yml' =>
                'steps:' . "\n" . '  - uses: LibreCodeCoop/release-tool/actions/prepare@1111111111111111111111111111111111111111',
        ]));

        self::assertArrayHasKey('nightly-release.yml', $verifier->mismatches(
            'HEAD',
            'refs/remotes/origin/main',
            ['nightly-release.yml'],
            ['LibreCodeCoop/release-tool/actions/'],
        ));
    }

    private function git(array $files): GitRepository
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('resolve')->willReturnMap([
            ['HEAD', self::CANDIDATE],
            ['refs/remotes/origin/main', self::REFERENCE],
        ]);
        $git->method('fileExists')->willReturnCallback(
            static fn (string $sha, string $path): bool => isset($files[$sha . ':' . $path]),
        );
        $git->method('readFile')->willReturnCallback(
            static fn (string $sha, string $path): string => $files[$sha . ':' . $path],
        );

        return $git;
    }
}
