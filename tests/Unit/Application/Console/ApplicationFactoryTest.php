<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Console;

use LibreCode\ReleaseTool\Application\Console\ApplicationFactory;
use PHPUnit\Framework\TestCase;

final class ApplicationFactoryTest extends TestCase
{
    public function testCreatesNamedVersionedApplication(): void
    {
        $application = ApplicationFactory::create();

        self::assertSame('release-tool', $application->getName());
        self::assertSame('0.1.0-dev', $application->getVersion());
        self::assertFalse($application->isAutoExitEnabled());
    }
}
