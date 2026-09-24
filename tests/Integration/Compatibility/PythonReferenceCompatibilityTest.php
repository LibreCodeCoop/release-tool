<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreCode\ReleaseTool\Tests\Integration\Compatibility;

use LibreCode\ReleaseTool\Tests\Support\Compatibility\PhpReleaseToolTarget;
use LibreCode\ReleaseTool\Tests\Support\Compatibility\PythonReferenceTarget;
use LibreCode\ReleaseTool\Tests\Support\Compatibility\ReleaseCompatibilityTarget;
use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PythonReferenceCompatibilityTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    /** @return iterable<string, array{ReleaseCompatibilityTarget}> */
    public static function authorizationAndStableTargets(): iterable
    {
        yield 'python' => [
            new PythonReferenceTarget(dirname(__DIR__, 2) . '/Fixtures/PythonReference'),
        ];
        yield 'php' => [
            new PhpReleaseToolTarget(dirname(__DIR__, 3)),
        ];
    }

    /** @return iterable<string, array{ReleaseCompatibilityTarget}> */
    public static function artifactTargets(): iterable
    {
        yield from self::authorizationAndStableTargets();
    }

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

    #[DataProvider('authorizationAndStableTargets')]
    public function testAuthorizationReportsAuthorizedPermissionAsJson(ReleaseCompatibilityTarget $target): void
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
            $process = $target->checkAuthorization(
                'LibreSign/libresign',
                'alice',
                'write',
                $apiUrl,
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

    #[DataProvider('authorizationAndStableTargets')]
    public function testAuthorizationMapsNotFoundToNoneAndExitThree(ReleaseCompatibilityTarget $target): void
    {
        [$server, $apiUrl] = $this->startServer(<<<'PHP'
<?php
http_response_code(404);
header('Content-Type: application/json');
echo '{}';
PHP);

        try {
            $process = $target->checkAuthorization(
                'LibreSign/libresign',
                'outsider',
                'read',
                $apiUrl,
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

    #[DataProvider('authorizationAndStableTargets')]
    public function testAuthorizationRejectsMissingTokenBeforeHttp(ReleaseCompatibilityTarget $target): void
    {
        [$server, $apiUrl, $log] = $this->startServer(<<<'PHP'
<?php
file_put_contents(getenv('REQUEST_LOG'), "requested\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['permission' => 'admin']);
PHP);

        try {
            $process = $target->checkAuthorization(
                'LibreSign/libresign',
                'alice',
                'write',
                $apiUrl,
                ['GITHUB_TOKEN' => ''],
            );

            self::assertSame(2, $process->getExitCode());
            self::assertFileDoesNotExist($log);
        } finally {
            $server->stop();
        }
    }

    #[DataProvider('authorizationAndStableTargets')]
    public function testStableSelectionWritesActionOutputsAndSummary(ReleaseCompatibilityTarget $target): void
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
        $process = $target->selectStable(
            'LibreSign/libresign',
            'stable15',
            [
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

    #[DataProvider('artifactTargets')]
    public function testArtifactValidationAcceptsMatchingNextcloudArchive(ReleaseCompatibilityTarget $target): void
    {
        $root = $this->temporaryDirectory('artifact-valid-');
        $tar = $root . '/libresign-v15.0.4.tar.gz';
        $this->createTarGz($tar, [
            'libresign/appinfo/info.xml' => '<info><version>15.0.4</version></info>',
            'libresign/CHANGELOG.md' => '# Changelog',
        ]);

        $process = $target->validateArtifact($tar, 'libresign', '15.0.4');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('Validated release artifact:', $process->getOutput());
    }

    #[DataProvider('artifactTargets')]
    public function testArtifactValidationRejectsTraversal(ReleaseCompatibilityTarget $target): void
    {
        $root = $this->temporaryDirectory('artifact-traversal-');
        $tar = $root . '/unsafe.tar.gz';
        $this->createUnsafeTarGz($tar);

        $process = $target->validateArtifact($tar, 'libresign', '15.0.4');

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsStringIgnoringCase('unsafe archive path: ../evil.txt', $process->getErrorOutput());
    }

    public function testReleaseNotesRejectsInvalidFallbackLimitBeforeGitOrHttp(): void
    {
        $root = $this->temporaryDirectory('release-notes-');
        $process = $this->target()->releaseNotes(
            'LibreSign/libresign',
            'stable15',
            $root,
            'https://api.github.test',
            'https://github.test',
            '',
            'HEAD',
            0,
            ['RELEASE_NOTES_GITHUB_TOKEN' => 'test-token'],
        );

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('::error::fallback-limit must be greater than zero', $process->getOutput());
    }

    #[DataProvider('artifactTargets')]
    public function testArtifactRestoreValidatesRunAndStripsCredentialsOnRedirect(ReleaseCompatibilityTarget $target): void
    {
        $root = $this->temporaryDirectory('artifact-restore-');
        $zip = $root . '/artifact.zip';
        $this->createZip($zip, ['payload/release.json' => '{"version":"15.0.4"}']);

        [$archiveServer, $archiveUrl, $archiveLog] = $this->startServer(
            <<<'PHP'
<?php
file_put_contents(getenv('REQUEST_LOG'), json_encode([
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
    'version' => $_SERVER['HTTP_X_GITHUB_API_VERSION'] ?? null,
]) . PHP_EOL, FILE_APPEND);
header('Content-Type: application/zip');
readfile(getenv('ARCHIVE_FILE'));
PHP,
            ['ARCHIVE_FILE' => $zip],
        );

        [$apiServer, $apiUrl] = $this->startServer(
            <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/artifact-download') {
    header('Location: ' . getenv('ARCHIVE_URL'), true, 302);
    return;
}
header('Content-Type: application/json');
if ($path === '/repos/LibreSign/libresign/actions/artifacts') {
    echo json_encode(['artifacts' => [[
        'id' => 11,
        'name' => 'release-package',
        'expired' => false,
        'created_at' => '2026-09-23T12:00:00Z',
        'archive_download_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/artifact-download',
        'workflow_run' => ['id' => 22, 'head_sha' => str_repeat('a', 40)],
    ]]]);
    return;
}
if ($path === '/repos/LibreSign/libresign/actions/runs/22') {
    echo json_encode([
        'event' => 'workflow_dispatch',
        'path' => '.github/workflows/build.yml',
    ]);
    return;
}
http_response_code(404);
echo '{}';
PHP,
            ['ARCHIVE_URL' => $archiveUrl . '/artifact.zip'],
        );

        try {
            $destination = $root . '/restored';
            $process = $target->restoreArtifact(
                'LibreSign/libresign',
                'release-package',
                str_repeat('a', 40),
                $destination,
                $apiUrl,
                'workflow_dispatch',
                '.github/workflows/build.yml',
                ['GITHUB_TOKEN' => 'top-secret-test-token'],
            );

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('{"version":"15.0.4"}', file_get_contents($destination . '/payload/release.json'));
            $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(11, $payload['artifact_id']);
            self::assertSame(22, $payload['workflow_run_id']);

            $redirectedRequest = json_decode(
                trim((string) file_get_contents($archiveLog)),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertNull($redirectedRequest['authorization']);
        } finally {
            $apiServer->stop();
            $archiveServer->stop();
        }
    }

    #[DataProvider('artifactTargets')]
    public function testArtifactRestoreRejectsPreexistingSymlinkEscape(ReleaseCompatibilityTarget $target): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Symlink fixture is Unix-oriented.');
        }

        $root = $this->temporaryDirectory('artifact-restore-symlink-');
        $outside = $this->temporaryDirectory('artifact-restore-outside-');
        $destination = $root . '/restored';
        mkdir($destination);
        self::assertTrue(symlink($outside, $destination . '/link'));

        $zip = $root . '/symlink-escape.zip';
        $this->createZip($zip, ['link/evil.txt' => 'evil']);

        [$archiveServer, $archiveUrl] = $this->startServer(
            <<<'PHP'
<?php
header('Content-Type: application/zip');
readfile(getenv('ARCHIVE_FILE'));
PHP,
            ['ARCHIVE_FILE' => $zip],
        );

        [$apiServer, $apiUrl] = $this->startServer(
            <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/repos/LibreSign/libresign/actions/artifacts') {
    echo json_encode(['artifacts' => [[
        'id' => 31,
        'name' => 'symlink-package',
        'expired' => false,
        'created_at' => '2026-09-23T12:00:00Z',
        'archive_download_url' => getenv('ARCHIVE_URL'),
        'workflow_run' => ['id' => 32, 'head_sha' => str_repeat('c', 40)],
    ]]]);
    return;
}
echo '{}';
PHP,
            ['ARCHIVE_URL' => $archiveUrl . '/artifact.zip'],
        );

        try {
            $process = $target->restoreArtifact(
                'LibreSign/libresign',
                'symlink-package',
                str_repeat('c', 40),
                $destination,
                null,
                null,
                $apiUrl,
                ['GITHUB_TOKEN' => 'test-token'],
            );

            self::assertSame(2, $process->getExitCode());
            self::assertFileDoesNotExist($outside . '/evil.txt');
        } finally {
            $apiServer->stop();
            $archiveServer->stop();
        }
    }

    #[DataProvider('artifactTargets')]
    public function testArtifactRestoreRejectsZipTraversal(ReleaseCompatibilityTarget $target): void
    {
        $root = $this->temporaryDirectory('artifact-restore-traversal-');
        $zip = $root . '/unsafe.zip';
        $this->createUnsafeZip($zip);

        [$server, $apiUrl] = $this->startServer(
            <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/artifact-download') {
    header('Content-Type: application/zip');
    readfile(getenv('ARCHIVE_FILE'));
    return;
}
header('Content-Type: application/json');
if ($path === '/repos/LibreSign/libresign/actions/artifacts') {
    echo json_encode(['artifacts' => [[
        'id' => 31,
        'name' => 'unsafe-package',
        'expired' => false,
        'created_at' => '2026-09-23T12:00:00Z',
        'archive_download_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/artifact-download',
        'workflow_run' => ['id' => 32, 'head_sha' => str_repeat('b', 40)],
    ]]]);
    return;
}
http_response_code(404);
echo '{}';
PHP,
            ['ARCHIVE_FILE' => $zip],
        );

        try {
            $process = $target->restoreArtifact(
                'LibreSign/libresign',
                'unsafe-package',
                str_repeat('b', 40),
                $root . '/restored',
                $apiUrl,
                environment: ['GITHUB_TOKEN' => 'test-token'],
            );

            self::assertNotSame(0, $process->getExitCode());
            self::assertStringContainsString('unsafe artifact path: ../evil.txt', $process->getErrorOutput());
            self::assertFileDoesNotExist($root . '/evil.txt');
        } finally {
            $server->stop();
        }
    }

    public function testReleaseNotesPreferPullRequestsDeduplicateAndSanitizeContributorText(): void
    {
        $root = $this->temporaryDirectory('release-notes-success-');
        $bin = $root . '/bin';
        mkdir($bin);
        $git = $bin . '/git';
        file_put_contents($git, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = "rev-list" ]; then
    printf '%s\n' sha1111111111111111111111111111111111111 sha2222222222222222222222222222222222222 sha3333333333333333333333333333333333333
    exit 0
fi
if [ "$1" = "show" ]; then
    case "${4:-}" in
        sha3333333333333333333333333333333333333) printf '%s\n' 'direct @bob _change_' ;;
        *) printf '%s\n' 'unused subject' ;;
    esac
    exit 0
fi
exit 2
SH);
        chmod($git, 0755);

        [$server, $apiUrl] = $this->startServer(<<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (str_contains($path, '/sha1111111111111111111111111111111111111/pulls')) {
    echo json_encode([[
        'number' => 10,
        'title' => 'Fix @alice *release*',
        'merged_at' => '2026-09-23T10:00:00Z',
        'base' => ['ref' => 'stable15'],
    ]]);
    return;
}
if (str_contains($path, '/sha2222222222222222222222222222222222222/pulls')) {
    echo json_encode([[
        'number' => 10,
        'title' => 'Fix @alice *release*',
        'merged_at' => '2026-09-23T10:00:00Z',
        'base' => ['ref' => 'stable15'],
    ]]);
    return;
}
echo '[]';
PHP);

        try {
            $output = $root . '/output';
            $runnerTemp = $root . '/runner';
            mkdir($runnerTemp);
            $process = $this->target()->releaseNotes(
                'LibreSign/libresign',
                'stable15',
                $root,
                $apiUrl,
                'https://github.example.test',
                'v15.0.3',
                'HEAD',
                10,
                [
                    'RELEASE_NOTES_GITHUB_TOKEN' => 'test-token',
                    'GITHUB_OUTPUT' => $output,
                    'RUNNER_TEMP' => $runnerTemp,
                    'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                ],
            );

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('Generated 2 change entries (1 pull requests, 1 direct commits).', $process->getOutput());

            $outputs = [];
            foreach (file($output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                [$name, $value] = explode('=', $line, 2);
                $outputs[$name] = $value;
            }
            self::assertSame('2', $outputs['change-count']);
            self::assertSame('1', $outputs['pull-request-count']);
            self::assertSame('1', $outputs['commit-fallback-count']);

            $changes = (string) file_get_contents($outputs['changes-file']);
            self::assertStringContainsString('- Fix @​alice \\*release\\* ([#10](https://github.example.test/LibreSign/libresign/pull/10))', $changes);
            self::assertStringContainsString('- direct @​bob \\_change\\_ (`sha3333`)', $changes);
            self::assertSame(2, substr_count($changes, PHP_EOL));
        } finally {
            $server->stop();
        }
    }

    private function target(): ReleaseCompatibilityTarget
    {
        return new PythonReferenceTarget(dirname(__DIR__, 2) . '/Fixtures/PythonReference');
    }

    /**
     * @return array{0: Process, 1: string, 2?: string}
     */
    private function startServer(string $router, array $environment = []): array
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
                ['REQUEST_LOG' => $log] + $environment,
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
        $archive->compress(\Phar::GZ);
        unset($archive);
        @unlink($tar);
    }

    /**
     * @param array<string, string> $files
     */
    private function createZip(string $path, array $files): void
    {
        $payload = json_encode($files, JSON_THROW_ON_ERROR);
        $code = <<<'PY'
import json
import sys
import zipfile

files = json.loads(sys.argv[2])
with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_DEFLATED) as archive:
    for name, content in files.items():
        archive.writestr(name, content)
PY;
        $process = new Process(['python3', '-c', $code, $path, $payload]);
        $process->mustRun();
    }

    private function createUnsafeZip(string $path): void
    {
        $code = <<<'PY'
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_DEFLATED) as archive:
    archive.writestr("../evil.txt", "evil")
PY;
        $process = new Process(['python3', '-c', $code, $path]);
        $process->mustRun();
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
