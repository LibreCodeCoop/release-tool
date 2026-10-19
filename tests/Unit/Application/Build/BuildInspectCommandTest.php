<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Build;

use LibreCode\ReleaseTool\Application\Build\BuildMetadataInspector;
use LibreCode\ReleaseTool\Application\Console\ApplicationFactory;
use LibreCode\ReleaseTool\Infrastructure\Build\LocalBuildFileReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildInspectCommandTest extends TestCase
{
    public function testWritesValidGithubOutputsFromOnDiskCheckout(): void
    {
        $root = sys_get_temp_dir().'/release-build-inspect-'.bin2hex(random_bytes(8));
        mkdir($root.'/appinfo', 0700, true);
        file_put_contents($root.'/appinfo/info.xml', '<info><version>15.0.8</version><dependencies><nextcloud min-version="35"/></dependencies></info>');
        file_put_contents($root.'/package.json', '{}');
        $outputPath = $root.'/github-output';

        try {
            $application = ApplicationFactory::create(
                buildMetadataInspector: new BuildMetadataInspector(new LocalBuildFileReader()),
            );
            $command = $application->find('build:inspect');
            $tester = new CommandTester($command);
            $exit = $tester->execute([
                '--root' => $root,
                '--github-output' => $outputPath,
                '--json' => true,
            ]);

            self::assertSame(0, $exit, $tester->getDisplay());
            $data = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('15.0.8', $data['version']);
            self::assertSame('^24', $data['node_version']);
            self::assertSame('35', $data['nextcloud_min_version']);
            self::assertSame(
                "version=15.0.8\nnextcloud-min-version=35\nnode-version=^24\nnpm-version=^11.3\nhas-package-json=true\n",
                file_get_contents($outputPath),
            );
        } finally {
            @unlink($outputPath);
            @unlink($root.'/package.json');
            @unlink($root.'/appinfo/info.xml');
            @rmdir($root.'/appinfo');
            @rmdir($root);
        }
    }

    public function testRejectsMissingCheckoutWithoutWritingOutputs(): void
    {
        $path = sys_get_temp_dir().'/release-missing-'.bin2hex(random_bytes(8));
        $application = ApplicationFactory::create(
            buildMetadataInspector: new BuildMetadataInspector(new LocalBuildFileReader()),
        );
        $tester = new CommandTester($application->find('build:inspect'));

        self::assertNotSame(0, $tester->execute([
            '--root' => $path,
            '--github-output' => $path.'.output',
        ]));
        self::assertFileDoesNotExist($path.'.output');
    }
}
