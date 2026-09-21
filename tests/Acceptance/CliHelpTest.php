<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use LibreCode\ReleaseTool\Application\Console\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class CliHelpTest extends TestCase
{
    public function testGlobalHelpListsUseCaseCommands(): void
    {
        $tester = new ApplicationTester(ApplicationFactory::create());

        $exit = $tester->run(['command' => 'list', '--raw' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('config:validate', $tester->getDisplay());
    }

    public function testConfigValidateHelpIsAvailable(): void
    {
        $tester = new ApplicationTester(ApplicationFactory::create());

        $exit = $tester->run(['command' => 'help', 'command_name' => 'config:validate']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Validate a release consumer configuration', $tester->getDisplay());
        self::assertStringContainsString('--config', $tester->getDisplay());
    }
}
