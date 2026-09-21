<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Configuration;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsumerConfigLoaderTest extends TestCase
{
    public function testLoadsLibreSignConfigurationWithSeparateAppAndBranchConcepts(): void
    {
        $config = (new ConsumerConfigLoader())->load($this->fixture('libresign.yml'));

        self::assertSame('libresign', $config->appId);
        self::assertSame('/^stable(?<nextcloud>\d+)$/', $config->stablePattern);
        self::assertSame('appinfo/info.xml', $config->versionSource);
        self::assertSame(['package.json', 'package-lock.json'], $config->versionMirrors);
        self::assertSame('docs/changelogs/changelog-{major}.md', $config->changelogPath);
        self::assertSame(['make', 'appstore', 'verify-appstore-package'], $config->packageCommand);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testRejectsInvalidConfiguration(string $fixture, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ConsumerConfigLoader())->load($this->fixture($fixture));
    }

    public static function invalidConfigurationProvider(): iterable
    {
        yield 'unknown key' => ['invalid-unknown-key.yml', 'Unknown configuration key'];
        yield 'shell command executable' => ['invalid-shell-command.yml', 'must not contain shell syntax'];
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Configuration/' . $name;
    }
}
