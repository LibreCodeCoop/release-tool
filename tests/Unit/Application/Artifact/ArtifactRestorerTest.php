<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Artifact;

use LibreCode\ReleaseTool\Application\Artifact\ActionsArtifact;
use LibreCode\ReleaseTool\Application\Artifact\ActionsWorkflowRun;
use LibreCode\ReleaseTool\Application\Artifact\ArtifactRestorer;
use LibreCode\ReleaseTool\Application\Artifact\ArtifactWorkflowOrigin;
use LibreCode\ReleaseTool\Application\Artifact\Port\ActionsArtifactRepository;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArtifactRestorerTest extends TestCase
{
    #[DataProvider('trustedOriginProvider')]
    public function testRestoresFromAnyTrustedOrigin(string $event, string $path): void
    {
        $repository = $this->repository(new ActionsWorkflowRun($event, $path));
        $extractor = $this->createMock(ArchiveExtractor::class);
        $extractor->expects(self::once())->method('extract');

        $restore = (new ArtifactRestorer($repository, $extractor))->restore(
            'LibreSign/libresign',
            'release-state-42',
            '/tmp/release-state',
            allowedOrigins: [
                new ArtifactWorkflowOrigin('pull_request_target', '.github/workflows/prepare-release.yml'),
                new ArtifactWorkflowOrigin('workflow_dispatch', '.github/workflows/resume-release.yml'),
            ],
        );

        self::assertSame(42, $restore->artifactId);
    }

    /** @return iterable<string, array{string,string}> */
    public static function trustedOriginProvider(): iterable
    {
        yield 'normal finalization' => [
            'pull_request_target',
            '.github/workflows/prepare-release.yml',
        ];
        yield 'manual resume' => [
            'workflow_dispatch',
            '.github/workflows/resume-release.yml',
        ];
    }

    public function testRejectsArtifactFromUntrustedOrigin(): void
    {
        $repository = $this->repository(
            new ActionsWorkflowRun('workflow_dispatch', '.github/workflows/untrusted.yml'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "artifact workflow origin 'workflow_dispatch:.github/workflows/untrusted.yml' is not trusted",
        );

        (new ArtifactRestorer($repository, $this->createMock(ArchiveExtractor::class)))->restore(
            'LibreSign/libresign',
            'release-state-42',
            '/tmp/release-state',
            allowedOrigins: [
                new ArtifactWorkflowOrigin('pull_request_target', '.github/workflows/prepare-release.yml'),
                new ArtifactWorkflowOrigin('workflow_dispatch', '.github/workflows/resume-release.yml'),
            ],
        );
    }

    private function repository(ActionsWorkflowRun $run): ActionsArtifactRepository
    {
        $repository = $this->createMock(ActionsArtifactRepository::class);
        $repository->method('artifacts')->willReturn([
            new ActionsArtifact(
                42,
                'release-state-42',
                false,
                '2026-09-29T18:00:00Z',
                'https://example.test/artifact.zip',
                99,
                str_repeat('a', 40),
            ),
        ]);
        $repository->method('workflowRun')->willReturn($run);
        $repository->method('download')->willReturnCallback(
            static function (string $url, string $destination): void {
                file_put_contents($destination, '');
            },
        );

        return $repository;
    }
}
