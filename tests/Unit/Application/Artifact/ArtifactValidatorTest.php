<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Artifact;

use LibreCode\ReleaseTool\Application\Artifact\ArtifactValidator;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReader;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveReaderFactory;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtifactValidatorTest extends TestCase
{
    public function testValidatesExpectedPackageContract(): void
    {
        $archivePath = $this->temporaryArtifact();
        $validator = new ArtifactValidator($this->factory([
            'libresign/appinfo/info.xml' => '<info><id>libresign</id><version>15.2.1</version></info>',
            'libresign/CHANGELOG.md' => "# Changelog\n\n## 15.2.1 - 2026-09-21\n",
            'libresign/lib/Foo.php' => '<?php',
            'libresign/css/app.css' => '',
        ]));

        $result = $validator->validate($archivePath, $this->config(), 'libresign', '15.2.1');

        self::assertTrue($result->valid);
        self::assertSame('libresign', $result->actualAppId);
        self::assertSame('15.2.1', $result->actualVersion);
        self::assertTrue($result->changelog['release_section_found']);
        self::assertSame([], $result->errors);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->sha256);
    }

    #[DataProvider('invalidPackageProvider')]
    public function testReportsInvalidPackageContracts(array $files, string $expectedError): void
    {
        $archivePath = $this->temporaryArtifact();
        $validator = new ArtifactValidator($this->factory($files));

        $result = $validator->validate($archivePath, $this->config(), 'libresign', '15.2.1');

        self::assertFalse($result->valid);
        self::assertStringContainsString($expectedError, implode("\n", $result->errors));
    }

    public static function invalidPackageProvider(): iterable
    {
        $info = '<info><id>libresign</id><version>15.2.1</version></info>';
        $changelog = "# Changelog\n\n## 15.2.1 - 2026-09-21\n";

        yield 'unexpected top level' => [[
            'other/appinfo/info.xml' => $info,
            'other/CHANGELOG.md' => $changelog,
        ], 'exactly one top-level app directory named libresign'];

        yield 'missing info xml' => [[
            'libresign/CHANGELOG.md' => $changelog,
            'libresign/lib/Foo.php' => '<?php',
            'libresign/css/app.css' => '',
        ], 'Missing required package metadata'];

        yield 'version mismatch' => [[
            'libresign/appinfo/info.xml' => '<info><id>libresign</id><version>15.2.0</version></info>',
            'libresign/CHANGELOG.md' => $changelog,
            'libresign/lib/Foo.php' => '<?php',
            'libresign/css/app.css' => '',
        ], 'Packaged app version mismatch'];

        yield 'missing changelog' => [[
            'libresign/appinfo/info.xml' => $info,
            'libresign/lib/Foo.php' => '<?php',
            'libresign/css/app.css' => '',
        ], 'Missing configured package changelog'];

        yield 'wrong changelog' => [[
            'libresign/appinfo/info.xml' => $info,
            'libresign/CHANGELOG.md' => "# Changelog\n\n## 15.2.0\n",
            'libresign/lib/Foo.php' => '<?php',
            'libresign/css/app.css' => '',
        ], 'does not contain a release section'];

        yield 'missing required path' => [[
            'libresign/appinfo/info.xml' => $info,
            'libresign/CHANGELOG.md' => $changelog,
            'libresign/lib/Foo.php' => '<?php',
        ], 'Missing configured required package path: css'];

        yield 'forbidden path' => [[
            'libresign/appinfo/info.xml' => $info,
            'libresign/CHANGELOG.md' => $changelog,
            'libresign/lib/Foo.php' => '<?php',
            'libresign/css/app.css' => '',
            'libresign/tests/BadTest.php' => '<?php',
        ], 'Forbidden package path is present: tests'];
    }

    private function config(): ConsumerConfig
    {
        return new ConsumerConfig(
            1,
            'libresign',
            'main',
            'LibreSign/libresign',
            '^stable(?<nextcloud>\\d+)$',
            'appinfo/info.xml',
            ['package.json', 'package-lock.json'],
            'v',
            'reachable-tag',
            null,
            'per-major',
            'docs/changelogs/changelog-{major}.md',
            'CHANGELOG.md',
            'Next Patch ({nextcloud})',
            'Next RC ({nextcloud})',
            'maintain',
            'maintain',
            ['make', 'appstore'],
            ['appinfo', 'lib', 'css'],
            ['tests', 'node_modules'],
        );
    }

    /** @param array<string, string> $files */
    private function factory(array $files): ArchiveReaderFactory
    {
        return new class($files) implements ArchiveReaderFactory {
            /** @param array<string, string> $files */
            public function __construct(private readonly array $files)
            {
            }

            public function open(string $path): ArchiveReader
            {
                return new class($this->files) implements ArchiveReader {
                    /** @param array<string, string> $files */
                    public function __construct(private readonly array $files)
                    {
                    }

                    public function paths(): array
                    {
                        return array_keys($this->files);
                    }

                    public function read(string $path): string
                    {
                        return $this->files[$path];
                    }
                };
            }
        };
    }

    private function temporaryArtifact(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'release-tool-artifact-');
        self::assertIsString($path);
        file_put_contents($path, 'artifact');

        $this->registerCleanup(static fn () => @unlink($path));

        return $path;
    }

    private function registerCleanup(callable $cleanup): void
    {
        register_shutdown_function($cleanup);
    }
}
