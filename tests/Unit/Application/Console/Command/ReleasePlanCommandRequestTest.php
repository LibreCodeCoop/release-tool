<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommandRequest;
use LibreCode\ReleaseTool\Application\Release\Exception\InvalidReleasePlanRequest;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

final class ReleasePlanCommandRequestTest extends TestCase
{
    public function testBuildsTypedRequestFromConsoleOptions(): void
    {
        $request = ReleasePlanCommandRequest::fromInput($this->input([
            'config' => 'config.yml',
            'root' => '/repo',
            'branch' => ' stable35 ',
            'ref' => ' abc123 ',
            'release-version' => ' 15.0.5 ',
            'channel' => 'final',
            'mode' => 'security',
            'safe-public-text' => ' public-safe ',
            'ignore-open-backport' => true,
            'create-follow-up-milestone' => true,
            'json' => true,
            'output-file' => ' /tmp/plan.json ',
            'github-output' => ' /tmp/output ',
            'github-step-summary' => ' /tmp/summary ',
            'github-annotations' => true,
            'tool-version' => '0.11.9',
        ]));

        self::assertSame('config.yml', $request->configPath);
        self::assertSame('/repo', $request->root);
        self::assertSame('stable35', $request->planInput->branch);
        self::assertSame('abc123', $request->planInput->ref);
        self::assertSame('15.0.5', $request->planInput->versionOverride);
        self::assertSame(ReleaseChannel::Final, $request->planInput->channel);
        self::assertSame(ReleaseMode::Security, $request->planInput->mode);
        self::assertSame('public-safe', $request->planInput->safePublicText);
        self::assertTrue($request->planInput->ignoreOpenBackport);
        self::assertTrue($request->planInput->createFollowUpMilestone);
        self::assertTrue($request->output->json);
        self::assertSame('/tmp/plan.json', $request->output->outputFile);
        self::assertTrue($request->output->githubAnnotations);
        self::assertSame('0.11.9', $request->output->toolVersion);
    }

    #[DataProvider('invalidOptionProvider')]
    public function testRejectsInvalidOptions(array $options, string $message): void
    {
        $this->expectException(InvalidReleasePlanRequest::class);
        $this->expectExceptionMessage($message);

        ReleasePlanCommandRequest::fromInput($this->input($options));
    }

    /** @return iterable<string, array{array<string,mixed>,string}> */
    public static function invalidOptionProvider(): iterable
    {
        yield 'missing branch' => [
            ['branch' => null],
            '--branch is required.',
        ];
        yield 'blank branch' => [
            ['branch' => '   '],
            '--branch is required.',
        ];
        yield 'invalid channel' => [
            ['branch' => 'stable35', 'channel' => 'nightly'],
            'Invalid --channel; expected alpha, beta, rc or final.',
        ];
        yield 'invalid mode' => [
            ['branch' => 'stable35', 'mode' => 'experimental'],
            'Invalid --mode; expected normal or security.',
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function input(array $overrides): InputInterface
    {
        $defaults = [
            'config' => '.nextcloud-release.yml',
            'root' => '.',
            'branch' => 'stable35',
            'ref' => null,
            'release-version' => null,
            'channel' => 'final',
            'mode' => 'normal',
            'safe-public-text' => null,
            'ignore-open-backport' => false,
            'create-follow-up-milestone' => false,
            'json' => false,
            'output-file' => null,
            'github-output' => null,
            'github-step-summary' => null,
            'github-annotations' => false,
            'tool-version' => 'unknown',
        ];
        $options = array_replace($defaults, $overrides);

        $input = $this->createMock(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn (string $name): mixed => $options[$name] ?? null,
        );

        return $input;
    }
}
