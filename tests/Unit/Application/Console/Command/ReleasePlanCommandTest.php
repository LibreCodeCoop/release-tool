<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Console\Command;

use DomainException;
use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommand;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommandRequest;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ReleasePlanCommandTest extends TestCase
{
    #[DataProvider('invalidInputProvider')]
    public function testRequestRejectsInvalidInput(array $options, string $message): void
    {
        $input = $this->createMock(InputInterface::class);
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
            'tool-version' => 'test',
        ];
        $values = array_replace($defaults, $options);
        $input->method('getOption')->willReturnCallback(
            static fn (string $name): mixed => $values[$name] ?? null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ReleasePlanCommandRequest::fromInput($input);
    }

    /** @return iterable<string, array{array<string,mixed>,string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'missing branch' => [
            ['branch' => ''],
            '--branch is required.',
        ];
        yield 'invalid channel' => [
            ['channel' => 'nightly'],
            'Invalid --channel; expected alpha, beta, rc or final.',
        ];
        yield 'invalid mode' => [
            ['mode' => 'unsafe'],
            'Invalid --mode; expected normal or security.',
        ];
    }

    public function testExpectedDomainFailureIsRenderedAsInvalidCommand(): void
    {
        $planner = $this->createMock(ReleasePlanning::class);
        $planner->method('plan')->willThrowException(new DomainException('Release cannot be planned.'));

        $tester = new CommandTester(new ReleasePlanCommand(
            $planner,
            contextValidator: new NoopConsumerConfigContextValidator(),
        ));

        $status = $tester->execute([
            '--branch' => 'stable35',
            '--config' => dirname(__DIR__, 4) . '/Fixtures/Configuration/libresign.yml',
        ]);

        self::assertSame(2, $status);
        self::assertStringContainsString('Release cannot be planned.', $tester->getDisplay());
    }

    public function testUnexpectedFailureIsNotMaskedAsUserError(): void
    {
        $planner = $this->createMock(ReleasePlanning::class);
        $planner->method('plan')->willThrowException(new \RuntimeException('Unexpected infrastructure failure.'));

        $tester = new CommandTester(new ReleasePlanCommand(
            $planner,
            contextValidator: new NoopConsumerConfigContextValidator(),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected infrastructure failure.');

        $tester->execute([
            '--branch' => 'stable35',
            '--config' => dirname(__DIR__, 4) . '/Fixtures/Configuration/libresign.yml',
        ]);
    }
}
