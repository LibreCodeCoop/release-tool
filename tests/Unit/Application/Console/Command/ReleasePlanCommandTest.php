<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Console\Command;

use DomainException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Configuration\NoopConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Console\Command\ReleasePlanCommand;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReleasePlanCommandTest extends TestCase
{
    public function testUnexpectedDomainFailureIsNotMaskedAsUserError(): void
    {
        $planner = $this->createMock(ReleasePlanning::class);
        $planner->method('plan')->willThrowException(new DomainException('Unexpected Git failure.'));

        $tester = new CommandTester(new ReleasePlanCommand(
            $planner,
            new ConsumerConfigLoader(),
            new NoopConsumerConfigContextValidator(),
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unexpected Git failure.');

        $tester->execute([
            '--branch' => 'stable35',
            '--config' => dirname(__DIR__, 4) . '/Fixtures/Configuration/libresign.yml',
        ]);
    }
}
