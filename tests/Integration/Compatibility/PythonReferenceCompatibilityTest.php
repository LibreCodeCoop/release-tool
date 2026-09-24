<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreCode\ReleaseTool\Tests\Integration\Compatibility;

use PharData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PythonReferenceCompatibilityTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }

    public function testAuthorizationReportsAuthorizedPermissionAsJson(): void
    {
        [$server, $apiUrl, $log] = $this->startServer(<<<'PHP'
<?php
file_put_contents(getenv('REQUEST_LOG'), json_encode([
    'uri' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
    'version' => $_SERVER['HTTP_X_GITHUB_API_VERSION'] ?? null,
]) . PHP_EOL, FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['permission' => 'maintain']);
PHP);

        try {
            $process = $this->python(
                'check_release_authorization.py',
                ['--repository', 'LibreSign/libresign', '--actor', 'alice', '--minimum', 'write', '--api-url', $apiUrl],
                ['GITHUB_TOKEN' => 'test-token'],
            );

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame([
                'actor' => 'alice',
                'repository' => 'LibreSign/libresign',
                'minimum_permission' => 'write',
                'actual_permission' => 'maintain',
                'authorized' => true,
            ], json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR));

            $request = json_decode(trim((string) file_get_contents($log)), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('/repos/LibreSign/libresign/collaborators/alice/permission', $request['uri']);
            self::assertSame('Bearer test-token', $request['authorization']);
            self::assertSame('application/vnd.github+json', $request['accept']);
            self::assertSame('2022-11-28', $request['version']);
        } finally {
            $server->stop();
        }
    }

    public function testAuthorizationMapsNotFoundToNoneAndExitThree(): void
    {
        [$server, $apiUrl] = $this->startServer(<<<'PHP'
<?php
http_response_code(404);
header('Content-Type: application/json');
echo '{}';
PHP);

        try {
            $process = $this->python(
                'check_release_authorization.py',
                ['--repository', 'LibreSign/libresign', '--actor', 'outsider', '--minimum', 'read', '--api-url', $apiUrl],
                ['GITHUB_TOKEN' => 'test-token'],
            );

            self::assertSame(3, $process->getExitCode(), $process->getErrorOutput());
            $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('none', $payload['actual_permission']);
            self::assertFalse($payload['authorized']);
        } finally {
            $server->stop();
        }
    }

    public function testStableSelectionWritesActionOutputsAndSummary(): void
    {
        $root = $this->temporaryDirectory('stable-select-');
        $bin = $root . '/bin';
        mkdir($bin);
        $git = $bin . '/git';
        file_put_contents($git, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' \
  'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/stable14' \
  'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb refs/heads/stable15' \
  'cccccccccccccccccccccccccccccccccccccccc refs/heads/stable9' \
  'dddddddddddddddddddddddddddddddddddddddd refs/heads/stable0' \
  'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee refs/heads/not-stable'
SH);
        chmod($git, 0755);

        $output = $root . '/output';
        $summary = $root . '/summary';
        $process = $this->python(
            'release_stable_select.py',
            [],
            [
                'INPUT_REPOSITORY' => 'LibreSign/libresign',
                'INPUT_BRANCH' => 'stable15',
                'GITHUB_OUTPUT' => $output,
                'GITHUB_STEP_SUMMARY' => $summary,
                'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
            ],
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Latest stable branch: stable15', $process->getOutput());
        self::assertStringContainsString('Publish nightly: true', $process->getOutput());
        self::assertSame(
            "current_branch=stable15\ncurrent_major=15\nlatest_branch=stable15\nlatest_major=15\nis_latest=true\n",
            file_get_contents($output),
        );
        self::assertStringContainsString('- Publish nightly: `true`', (string) file_get_contents($summary));
    }

    public function testArtifactValidationAcceptsMatchingNextcloudArchive(): void
    {
        $root = $this->temporaryDirectory('artifact-valid-');
        $tar = $root . '/libresign-v15.0.4.tar.gz';
        $this->createTarGz($tar, [
            'libresign/appinfo/info.xml' => '<info><version>15.0.4</version></info>',
            'libresign/CHANGELOG.md' => '# Changelog',
        ]);

        $process = $this->python(
            'validate_release_artifact.py',
            ['--artifact', $tar, '--app-name', 'libresign', '--version', '15.0.4'],
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Validated release artifact:', $process->getOutput());
    }

    public function testArtifactValidationRejectsTraversal(): void
    {
        $root = $this->temporaryDirectory('artifact-traversal-');
        $tar = $root . '/unsafe.tar.gz';
        $this->createUnsafeTarGz($tar);

        $process = $this->python(
            'validate_release_artifact.py',
            ['--artifact', $tar, '--app-name', 'libresign', '--version', '15.0.4'],
        );

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('unsafe archive path: ../evil.txt', $process->getErrorOutput());
    }

    public function testReleaseNotesRejectsInvalidFallbackLimitBeforeGitOrHttp(): void
    {
        $root = $this->temporaryDirectory('release-notes-');
        $process = $this->python(
            'release_notes_from_pull_requests.py',
            [],
            [
                'RELEASE_NOTES_GITHUB_TOKEN' => 'test-token',
                'RELEASE_NOTES_REPOSITORY' => 'LibreSign/libresign',
                'RELEASE_NOTES_BRANCH' => 'stable15',
                'RELEASE_NOTES_WORKING_DIRECTORY' => $root,
                'RELEASE_NOTES_FALLBACK_LIMIT' => '0',
            ],
        );

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('::error::fallback-limit must be greater than zero', $process->getOutput());
    }

    private function python(string $script, array $arguments, array $environment = []): Process
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/PythonReference/' . $script;
        $process = new Process(['python3', $path, ...$arguments], null, $environment + $_ENV + $_SERVER);
        $process->run();

        return $process;
    }

    /**
     * @return array{0: Process, 1: string, 2?: string}
     */
    private function startServer(string $router): array
    {
        $root = $this->temporaryDirectory('fake-github-');
        $routerPath = $root . '/router.php';
        $log = $root . '/requests.log';
        file_put_contents($routerPath, $router);

        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $port = random_int(20000, 45000);
            $process = new Process(
                ['php', '-S', '127.0.0.1:' . $port, $routerPath],
                $root,
                ['REQUEST_LOG' => $log] + $_ENV + $_SERVER,
            );
            $process->start();

            for ($probe = 0; $probe < 30; ++$probe) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
                if (is_resource($socket)) {
                    fclose($socket);

                    return [$process, 'http://127.0.0.1:' . $port, $log];
                }
                usleep(20_000);
            }
            $process->stop();
        }

        self::fail('Unable to start local fake GitHub API server.');
    }

    /**
     * @param array<string, string> $files
     */
    private function createTarGz(string $path, array $files): void
    {
        $tar = substr($path, 0, -3);
        $archive = new PharData($tar);
        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }
        $archive->compress(Phar::GZ);
        unset($archive);
        @unlink($tar);
    }

    private function createUnsafeTarGz(string $path): void
    {
        $code = <<<'PY'
import io
import tarfile
import sys

with tarfile.open(sys.argv[1], "w:gz") as archive:
    info = tarfile.TarInfo("../evil.txt")
    payload = b"evil"
    info.size = len(payload)
    archive.addfile(info, io.BytesIO(payload))
PY;
        $process = new Process(['python3', '-c', $code, $path]);
        $process->mustRun();
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        $this->paths[] = $path;

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
