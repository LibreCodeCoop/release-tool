<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Application\Release\Exception\ReleasePlanConfigurationFailure;
use LibreCode\ReleaseTool\Application\Release\Exception\ReleasePlanRuleViolation;
use LibreCode\ReleaseTool\Application\Release\PlanReleaseInput;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanning;
use LibreCode\ReleaseTool\Application\Release\ReleasePlanUseCase;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleasePlanUseCaseTest extends TestCase
{
    #[DataProvider('configurationFailureProvider')]
    public function testConfigurationFailuresAreTyped(
        string $configPath,
        ?string $contextError,
        string $expectedMessage,
    ): void {
        $planner = $this->createMock(ReleasePlanning::class);
        $planner->expects(self::never())->method('plan');

        $context = $this->createMock(ConsumerConfigContextValidator::class);
        if ($contextError !== null) {
            $context->method('validate')->willThrowException(new InvalidArgumentException($contextError));
        }

        $useCase = new ReleasePlanUseCase(
            $planner,
            new ConsumerConfigLoader(),
            $context,
        );

        $this->expectException(ReleasePlanConfigurationFailure::class);
        $this->expectExceptionMessage($expectedMessage);

        $useCase->execute($configPath, '.', $this->input());
    }

    /** @return iterable<string, array{string,?string,string}> */
    public static function configurationFailureProvider(): iterable
    {
        yield 'missing config file' => [
            '/definitely/missing/release-config.yml',
            null,
            'Configuration file not found',
        ];

        yield 'invalid repository context' => [
            self::fixtureConfig(),
            'Repository identity mismatch.',
            'Repository identity mismatch.',
        ];
    }

    public function testPlanningRuleViolationIsPreserved(): void
    {
        $planner = $this->createMock(ReleasePlanning::class);
        $planner->method('plan')->willThrowException(
            new ReleasePlanRuleViolation('No releasable activity exists after the previous release.'),
        );

        $useCase = new ReleasePlanUseCase(
            $planner,
            new ConsumerConfigLoader(),
            $this->validContext(),
        );

        $this->expectException(ReleasePlanRuleViolation::class);
        $this->expectExceptionMessage('No releasable activity exists');

        $useCase->execute(self::fixtureConfig(), '.', $this->input());
    }

    public function testUnexpectedInfrastructureFailureIsNotReclassified(): void
    {
        $planner = $this->createMock(ReleasePlanning::class);
        $planner->method('plan')->willThrowException(
            new \RuntimeException('git transport failed'),
        );

        $useCase = new ReleasePlanUseCase(
            $planner,
            new ConsumerConfigLoader(),
            $this->validContext(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('git transport failed');

        $useCase->execute(self::fixtureConfig(), '.', $this->input());
    }

    private function validContext(): ConsumerConfigContextValidator
    {
        return new class implements ConsumerConfigContextValidator {
            public function validate(ConsumerConfig $config, string $root): void
            {
            }
        };
    }

    private function input(): PlanReleaseInput
    {
        return new PlanReleaseInput(
            'stable35',
            null,
            null,
            ReleaseChannel::Final,
            false,
            false,
            ReleaseMode::Normal,
            null,
        );
    }

    private static function fixtureConfig(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Configuration/libresign.yml';
    }
}
