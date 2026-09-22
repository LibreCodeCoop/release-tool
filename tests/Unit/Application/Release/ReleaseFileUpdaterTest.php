<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\ReleaseFileUpdater;
use PHPUnit\Framework\TestCase;

final class ReleaseFileUpdaterTest extends TestCase
{
    public function testUpdatesXmlVersionWithoutReformattingDocument(): void
    {
        $input = "<info>\n  <id>example</id>\n  <version>1.2.3-dev.1</version>\n</info>\n";
        $result = (new ReleaseFileUpdater())->update('appinfo/info.xml', $input, '1.2.3');

        self::assertSame(
            "<info>\n  <id>example</id>\n  <version>1.2.3</version>\n</info>\n",
            $result,
        );
    }

    public function testPreservesTwoSpaceJsonFormatting(): void
    {
        $input = "{\n  \"name\": \"example\",\n  \"version\": \"1.2.3\",\n  \"nested\": {\n    \"enabled\": true\n  }\n}\n";

        $result = (new ReleaseFileUpdater())->update('package.json', $input, '1.2.4');

        self::assertSame(
            "{\n  \"name\": \"example\",\n  \"version\": \"1.2.4\",\n  \"nested\": {\n    \"enabled\": true\n  }\n}\n",
            $result,
        );
    }

    public function testUpdatesPackageLockRootAndRootPackageVersion(): void
    {
        $input = json_encode([
            'name' => 'example',
            'version' => '1.2.3-dev.1',
            'packages' => [
                '' => ['name' => 'example', 'version' => '1.2.3-dev.1'],
                'node_modules/example' => ['version' => '9.9.9'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";

        $result = (new ReleaseFileUpdater())->update('package-lock.json', $input, '1.2.3');
        $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('1.2.3', $decoded['version']);
        self::assertSame('1.2.3', $decoded['packages']['']['version']);
        self::assertSame('9.9.9', $decoded['packages']['node_modules/example']['version']);
    }

    public function testRejectsUnsupportedReleaseFileType(): void
    {
        $this->expectException(DomainException::class);
        (new ReleaseFileUpdater())->update('VERSION.txt', '1.0.0', '1.0.1');
    }
}
