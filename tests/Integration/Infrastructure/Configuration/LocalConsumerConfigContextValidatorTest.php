<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Integration\Infrastructure\Configuration;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigLoader;
use LibreCode\ReleaseTool\Infrastructure\Configuration\LocalConsumerConfigContextValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LocalConsumerConfigContextValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/release-tool-config-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
        mkdir($this->directory . '/appinfo', 0777, true);
        file_put_contents($this->directory . '/appinfo/info.xml', '<info><version>1.0.0</version></info>');
        file_put_contents($this->directory . '/package.json', '{}');
        file_put_contents($this->directory . '/package-lock.json', '{}');

        $this->git(['init', '-b', 'main']);
        $this->git(['remote', 'add', 'origin', 'https://github.com/LibreSign/libresign.git']);
        putenv('GITHUB_REPOSITORY');
    }

    protected function tearDown(): void
    {
        putenv('GITHUB_REPOSITORY');
        if (isset($this->directory) && is_dir($this->directory)) {
            (new Process(['rm', '-rf', $this->directory]))->run();
        }
    }

    public function testValidatesConfiguredFilesAndMatchingGitIdentity(): void
    {
        $config = (new ConsumerConfigLoader())->load(
            dirname(__DIR__, 3) . '/Fixtures/Configuration/libresign.yml',
        );

        (new LocalConsumerConfigContextValidator())->validate($config, $this->directory);

        self::addToAssertionCount(1);
    }

    public function testRejectsMissingConfiguredReleaseFile(): void
    {
        unlink($this->directory . '/package-lock.json');
        $config = (new ConsumerConfigLoader())->load(
            dirname(__DIR__, 3) . '/Fixtures/Configuration/libresign.yml',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured release file does not exist: package-lock.json');

        (new LocalConsumerConfigContextValidator())->validate($config, $this->directory);
    }

    public function testRejectsEnvironmentRepositoryMismatch(): void
    {
        putenv('GITHUB_REPOSITORY=Example/other');
        $config = (new ConsumerConfigLoader())->load(
            dirname(__DIR__, 3) . '/Fixtures/Configuration/libresign.yml',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Repository identity mismatch');

        (new LocalConsumerConfigContextValidator())->validate($config, $this->directory);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): void
    {
        (new Process(['git', ...$arguments], $this->directory))->mustRun();
    }
}
