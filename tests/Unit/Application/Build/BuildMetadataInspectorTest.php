<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Build;

use DomainException;
use LibreCode\ReleaseTool\Application\Build\BuildMetadata;
use LibreCode\ReleaseTool\Application\Build\BuildMetadataInspector;
use LibreCode\ReleaseTool\Application\Build\Port\BuildFileReader;
use PHPUnit\Framework\TestCase;

final class BuildMetadataInspectorTest extends TestCase
{
    private const string XML = '<info><version>15.1.2</version><dependencies><nextcloud min-version="35" max-version="36"/></dependencies></info>';

    public function testReadsVersionCompatibilityAndDeclaredEngines(): void
    {
        $result = $this->inspect([
            'appinfo/info.xml' => self::XML,
            'package.json' => '{"engines":{"node":">=22 <25","npm":"^11.4"}}',
        ]);

        self::assertSame('15.1.2', $result->version);
        self::assertSame('35', $result->nextcloudMinVersion);
        self::assertSame('>=22 <25', $result->nodeVersion);
        self::assertSame('^11.4', $result->npmVersion);
        self::assertTrue($result->hasPackageJson);
    }

    public function testMissingPackageJsonSkipsNodeBuild(): void
    {
        $result = $this->inspect(['appinfo/info.xml' => self::XML]);
        self::assertFalse($result->hasPackageJson);
        self::assertSame('', $result->nodeVersion);
        self::assertSame('', $result->npmVersion);
    }

    public function testPresentPackageWithoutEnginesUsesFallbacks(): void
    {
        foreach (['{}', '{"engines":{}}', '{"engines":{"node":"","npm":""}}'] as $json) {
            $result = $this->inspect(['appinfo/info.xml' => self::XML, 'package.json' => $json]);
            self::assertTrue($result->hasPackageJson);
            self::assertSame('^24', $result->nodeVersion);
            self::assertSame('^11.3', $result->npmVersion);
        }
    }

    public function testMissingXmlFails(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Missing appinfo/info.xml');
        $this->inspect([]);
    }

    public function testMalformedXmlFails(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid appinfo/info.xml');
        $this->inspect(['appinfo/info.xml' => '<info><version>1']);
    }

    public function testMissingCompatibilityFails(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('numeric Nextcloud min-version');
        $this->inspect(['appinfo/info.xml' => '<info><version>15.1.2</version></info>']);
    }

    public function testMalformedPackageJsonFails(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid package.json');
        $this->inspect(['appinfo/info.xml' => self::XML, 'package.json' => '{']);
    }

    public function testPackageJsonMustBeObject(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('package.json must be an object');
        $this->inspect(['appinfo/info.xml' => self::XML, 'package.json' => '[]']);
    }

    public function testEnginesMustBeObject(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('engines must be an object');
        $this->inspect(['appinfo/info.xml' => self::XML, 'package.json' => '{"engines":[]}']);
    }

    public function testEngineVersionMustBeString(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('engines.node must be a string');
        $this->inspect(['appinfo/info.xml' => self::XML, 'package.json' => '{"engines":{"node":24}}']);
    }

    public function testNewlineInEngineIsRejectedForGithubOutputs(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('control characters');
        $this->inspect(['appinfo/info.xml' => self::XML, 'package.json' => '{"engines":{"npm":"11.0\nmalicious=yes"}}']);
    }

    /** @param array<string, string> $files */
    private function inspect(array $files): BuildMetadata
    {
        $reader = new class($files) implements BuildFileReader {
            /** @param array<string, string> $files */
            public function __construct(private array $files)
            {
            }

            public function read(string $root, string $path): ?string
            {
                return $this->files[$path] ?? null;
            }
        };

        return (new BuildMetadataInspector($reader))->inspect('.');
    }
}
