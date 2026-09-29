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

    private function git(array $files): GitRepository
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('resolve')->willReturnMap([
            ['HEAD', self::CANDIDATE],
            ['refs/remotes/origin/main', self::REFERENCE],
        ]);
        $git->method('readFile')->willReturnCallback(
            static fn (string $sha, string $path): string => $files[$sha . ':' . $path],
        );

        return $git;
    }
}
