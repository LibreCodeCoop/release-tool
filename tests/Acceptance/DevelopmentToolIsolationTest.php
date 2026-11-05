<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevelopmentToolIsolationTest extends TestCase
{
    public function testRootComposerOnlyRequiresTheBinPluginForDevelopment(): void
    {
        $composer = self::readJson(dirname(__DIR__, 2) . '/composer.json');

        self::assertSame(
            ['bamarni/composer-bin-plugin'],
            array_keys($composer['require-dev']),
        );
        self::assertTrue($composer['config']['allow-plugins']['bamarni/composer-bin-plugin']);
        self::assertFalse($composer['extra']['bamarni-bin']['bin-links']);
        self::assertFalse($composer['extra']['bamarni-bin']['forward-command']);
    }

    #[DataProvider('isolatedToolPackages')]
    public function testEachToolIsLockedSeparatelyAndInvokedFromItsOwnNamespace(
        string $namespace,
        string $package,
        string $script,
    ): void {
        $root = dirname(__DIR__, 2);
        $toolRoot = $root . '/vendor-bin/' . $namespace;
        $composer = self::readJson($toolRoot . '/composer.json');
        $lock = self::readJson($toolRoot . '/composer.lock');
        $project = self::readJson($root . '/composer.json');

        self::assertArrayHasKey($package, $composer['require-dev']);
        self::assertArrayNotHasKey($package, $project['require-dev']);
        self::assertContains(
            $package,
            array_column(array_merge($lock['packages'], $lock['packages-dev']), 'name'),
        );
        self::assertStringContainsString(
            'vendor-bin/' . $namespace . '/vendor/bin/',
            $project['scripts'][$script],
        );
    }

    public function testInfectionUsesTheIsolatedPhpUnitExecutable(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2) . '/infection.json5');

        self::assertStringContainsString(
            '"customPath": "vendor-bin/phpunit/vendor/bin/phpunit"',
            $configuration,
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function isolatedToolPackages(): iterable
    {
        yield 'PHPUnit' => ['phpunit', 'phpunit/phpunit', 'test'];
        yield 'Infection' => ['infection', 'infection/infection', 'infection'];
        yield 'PHPStan' => ['phpstan', 'phpstan/phpstan', 'phpstan'];
        yield 'Psalm' => ['psalm', 'vimeo/psalm', 'psalm'];
        yield 'PHPMD' => ['phpmd', 'phpmd/phpmd', 'phpmd'];
        yield 'PHPCS' => ['phpcs', 'squizlabs/php_codesniffer', 'phpcs'];
        yield 'PHP-CS-Fixer' => ['php-cs-fixer', 'friendsofphp/php-cs-fixer', 'cs:check'];
        yield 'Rector' => ['rector', 'rector/rector', 'rector:check'];
        yield 'Deptrac' => ['deptrac', 'deptrac/deptrac', 'deptrac'];
        yield 'Box' => ['box', 'humbug/box', 'build:phar'];
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
