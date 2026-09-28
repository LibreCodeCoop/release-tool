<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Console\Command\ReleaseValidateIdentityCommand;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseMetadata;
use LibreCode\ReleaseTool\Application\Release\ReleaseIdentityValidator;
use LibreCode\ReleaseTool\Domain\Version\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReleaseValidateIdentityCommandTest extends TestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testWritesGitHubOutputsAndSummary(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('resolve')->with('HEAD')->willReturn(self::SHA);
        $git->expects(self::once())->method('tagExists')->with('v13.4.3')->willReturn(true);

        $metadata = $this->createMock(ReleaseMetadataReader::class);
        $metadata->method('read')->willReturn(
            new ReleaseMetadata(Version::parse('13.4.3'), 33, 33, []),
        );

        $command = new ReleaseValidateIdentityCommand(
            new ReleaseIdentityValidator($git, $metadata),
            new NoopConsumerConfigContextValidator(),
        );

        $outputFile = tempnam(sys_get_temp_dir(), 'release-output-');
        $summaryFile = tempnam(sys_get_temp_dir(), 'release-summary-');
        self::assertIsString($outputFile);
        self::assertIsString($summaryFile);

        try {
            $tester = new CommandTester($command);
            $status = $tester->execute([
                '--tag' => 'v13.4.3',
                '--config' => dirname(__DIR__, 4) . '/Fixtures/Configuration/libresign.yml',
                '--ref' => 'HEAD',
                '--require-tag-exists' => 'true',
                '--github-output' => $outputFile,
                '--github-step-summary' => $summaryFile,
            ]);

            self::assertSame(0, $status);
            self::assertSame(
                "tag=v13.4.3\nversion=13.4.3\nsha=" . self::SHA . "\n",
                file_get_contents($outputFile),
            );
            self::assertStringContainsString('Tag: `v13.4.3`', (string) file_get_contents($summaryFile));
            self::assertStringContainsString('App version: `13.4.3`', (string) file_get_contents($summaryFile));
            self::assertStringContainsString('Result: **valid**', (string) file_get_contents($summaryFile));
        } finally {
            @unlink($outputFile);
            @unlink($summaryFile);
        }
    }

    public function testRejectsInvalidBooleanWithClearMessage(): void
    {
        $git = $this->createMock(GitRepository::class);
        $metadata = $this->createMock(ReleaseMetadataReader::class);

        $command = new ReleaseValidateIdentityCommand(
            new ReleaseIdentityValidator($git, $metadata),
            new NoopConsumerConfigContextValidator(),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--tag' => 'v13.4.3',
            '--config' => dirname(__DIR__, 4) . '/Fixtures/Configuration/libresign.yml',
            '--require-tag-exists' => 'sometimes',
        ]);

        self::assertSame(2, $status);
        self::assertStringContainsString(
            "require-tag-exists must be either 'true' or 'false'.",
            $tester->getDisplay(),
        );
    }
}
