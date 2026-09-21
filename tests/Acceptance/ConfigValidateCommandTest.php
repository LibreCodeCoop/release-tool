<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use LibreCode\ReleaseTool\Application\Console\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ConfigValidateCommandTest extends TestCase
{
    public function testValidConfigurationHasVersionedJsonOutput(): void
    {
        $tester = new ApplicationTester(ApplicationFactory::create());
        $fixture = dirname(__DIR__) . '/Fixtures/Configuration/libresign.yml';

        $exit = $tester->run([
            'command' => 'config:validate',
            '--config' => $fixture,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertSame(
            [
                'schema' => 1,
                'valid' => true,
                'app_id' => 'libresign',
                'repository' => 'LibreSign/libresign',
                'authorization' => [
                    'prepare_min_permission' => 'maintain',
                    'merge_min_permission' => 'maintain',
                ],
            ],
            json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testInvalidConfigurationReturnsInvalidExitCode(): void
    {
        $tester = new ApplicationTester(ApplicationFactory::create());
        $fixture = dirname(__DIR__) . '/Fixtures/Configuration/invalid-unknown-key.yml';

        $exit = $tester->run([
            'command' => 'config:validate',
            '--config' => $fixture,
            '--json' => true,
        ]);

        self::assertSame(2, $exit);
        self::assertFalse(json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR)['valid']);
    }
}
