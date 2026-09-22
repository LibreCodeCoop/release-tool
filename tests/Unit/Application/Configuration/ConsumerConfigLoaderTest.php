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
        self::assertSame(['appinfo', 'lib', 'css', 'js', 'vendor', '3rdparty'], $config->packageRequiredPaths);
        self::assertSame(['.git', 'node_modules', 'tests'], $config->packageForbiddenPaths);
        self::assertSame('appstore-build-publish.yml', $config->publicationPublisherWorkflow);
        self::assertSame('{app}-{tag}.tar.gz', $config->publicationAssetName);
        self::assertSame('https://apps.nextcloud.com/api/v1/platform/{nextcloud}.0.0/apps.json', $config->publicationAppStoreApi);
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
