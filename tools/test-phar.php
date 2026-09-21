<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$phar = dirname(__DIR__) . '/build/release-tool.phar';
if (!is_file($phar)) {
    fwrite(STDERR, "PHAR not found: {$phar}\n");
    exit(2);
}

$run = static function (array $arguments, ?string $cwd = null, array $env = []): Process {
    $process = new Process($arguments, $cwd, $env === [] ? null : $env);
    $process->setTimeout(30);
    $process->run();

    if (!$process->isSuccessful()) {
        fwrite(STDERR, $process->getErrorOutput());
        fwrite(STDERR, $process->getOutput());
        exit($process->getExitCode() ?? 1);
    }

    return $process;
};

foreach ([['--version'], ['list', '--raw'], ['help'], ['metadata:inspect', '--help'], ['release:plan', '--help'], ['release:prepare', '--help']] as $arguments) {
    $run([PHP_BINARY, $phar, ...$arguments]);
}

$root = sys_get_temp_dir() . '/release-tool-phar-' . bin2hex(random_bytes(8));
mkdir($root, 0777, true);
mkdir($root . '/appinfo', 0777, true);
mkdir($root . '/docs/changelogs', 0777, true);

try {
    file_put_contents($root . '/appinfo/info.xml', <<<'XML'
<info>
  <version>1.0.0</version>
  <dependencies>
    <nextcloud min-version="35" max-version="35" />
  </dependencies>
</info>
XML);
    file_put_contents($root . '/package.json', "{\"version\":\"1.0.0\"}\n");
    file_put_contents($root . '/package-lock.json', "{\"version\":\"1.0.0\"}\n");
    file_put_contents($root . '/docs/changelogs/changelog-1.md', "# Changelog\n\n## 1.0.0 - 2026-09-21\n\n### Fixed\n\n- Initial.\n");
    file_put_contents($root . '/.nextcloud-release.yml', <<<'YAML'
schema: 1
repository: Example/app
app:
  id: example
  main_branch: main
branches:
  stable_pattern: '^stable(?<nextcloud>\d+)$'
version:
  source: appinfo/info.xml
  mirrors:
    - package.json
    - package-lock.json
  tag_prefix: v
history:
  previous_release: reachable-tag
changelog:
  strategy: per-major
  path: 'docs/changelogs/changelog-{major}.md'
  package_root: CHANGELOG.md
milestones:
  patch: 'Next Patch ({nextcloud})'
  rc: 'Next RC ({nextcloud})'
authorization:
  prepare_min_permission: maintain
  merge_min_permission: maintain
package:
  command:
    - make
    - package
YAML);

    $git = static fn (array $args): Process => $run(['git', ...$args], $root);
    $git(['init', '-b', 'stable35']);
    $git(['config', 'user.name', 'Release Tool PHAR Test']);
    $git(['config', 'user.email', 'release-tool@example.invalid']);
    $git(['remote', 'add', 'origin', 'https://github.com/Example/app.git']);
    $git(['add', '.']);
    $git(['commit', '-m', 'chore: initial release']);
    $git(['tag', 'v1.0.0']);
    file_put_contents($root . '/change.txt', "fix\n");
    $git(['add', 'change.txt']);
    $git(['commit', '-m', 'fix: deterministic PHAR planning']);

    $sourceMetadata = $run(
        [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/release-tool',
            'metadata:inspect',
            '--config', $root . '/.nextcloud-release.yml',
            '--root', $root,
            '--ref', 'v1.0.0',
            '--json',
        ],
        $root,
        ['GITHUB_REPOSITORY' => 'Example/app'],
    );
    $pharMetadata = $run(
        [
            PHP_BINARY,
            $phar,
            'metadata:inspect',
            '--config', $root . '/.nextcloud-release.yml',
            '--root', $root,
            '--ref', 'v1.0.0',
            '--json',
        ],
        $root,
        ['GITHUB_REPOSITORY' => 'Example/app'],
    );
    if ($sourceMetadata->getOutput() !== $pharMetadata->getOutput()) {
        throw new RuntimeException('Source CLI and PHAR metadata inspection differ.');
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Could not allocate local API port: {$error}");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

    $router = $root . '/router.php';
    file_put_contents($router, <<<'PHP'
<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
header('Content-Type: application/json');

if ($path === '/repos/Example/app/releases/tags/v1.0.1') {
    http_response_code(404);
    echo json_encode(['message' => 'Not Found']);
    return;
}
if ($path === '/repos/Example/app/milestones') {
    echo json_encode([[
        'number' => 7,
        'title' => 'Next Patch (35)',
        'html_url' => 'https://example.test/milestones/7',
    ]]);
    return;
}
if ($path === '/repos/Example/app/pulls') {
    echo json_encode([]);
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not Found']);
PHP);

    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], $root);
    $server->start();

    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $connection = @fsockopen('127.0.0.1', $port, $errorNumber, $errorString, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Local fake GitHub API did not start.');
    }

    try {
        $source = $run(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/bin/release-tool',
                'release:plan',
                '--config', $root . '/.nextcloud-release.yml',
                '--root', $root,
                '--branch', 'stable35',
                '--json',
            ],
            $root,
            [
                'GITHUB_API_URL' => "http://127.0.0.1:{$port}",
                'GITHUB_REPOSITORY' => 'Example/app',
            ],
        );

        $packaged = $run(
            [
                PHP_BINARY,
                $phar,
                'release:plan',
                '--config', $root . '/.nextcloud-release.yml',
                '--root', $root,
                '--branch', 'stable35',
                '--json',
            ],
            $root,
            [
                'GITHUB_API_URL' => "http://127.0.0.1:{$port}",
                'GITHUB_REPOSITORY' => 'Example/app',
            ],
        );

        $sourcePlan = json_decode($source->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $pharPlan = json_decode($packaged->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if ($sourcePlan !== $pharPlan) {
            throw new RuntimeException('Source CLI and PHAR release plans differ.');
        }
        if (($pharPlan['schema'] ?? null) !== 1 || ($pharPlan['proposed_version'] ?? null) !== '1.0.1') {
            throw new RuntimeException('PHAR release planning fixture did not produce ReleasePlan v1 patch output.');
        }

        $planPath = $root . '/release-plan.json';
        file_put_contents(
            $planPath,
            json_encode($sourcePlan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $sourcePreparation = $run(
            [
                PHP_BINARY,
                dirname(__DIR__) . '/bin/release-tool',
                'release:prepare',
                '--plan', $planPath,
                '--config', $root . '/.nextcloud-release.yml',
                '--root', $root,
                '--json',
            ],
            $root,
            [
                'GITHUB_REPOSITORY' => 'Example/app',
            ],
        );

        $pharPreparation = $run(
            [
                PHP_BINARY,
                $phar,
                'release:prepare',
                '--plan', $planPath,
                '--config', $root . '/.nextcloud-release.yml',
                '--root', $root,
                '--json',
            ],
            $root,
            [
                'GITHUB_REPOSITORY' => 'Example/app',
            ],
        );

        $sourcePreparationData = json_decode(
            $sourcePreparation->getOutput(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $pharPreparationData = json_decode(
            $pharPreparation->getOutput(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if ($sourcePreparationData !== $pharPreparationData) {
            throw new RuntimeException('Source CLI and PHAR release preparations differ.');
        }
        if (
            ($pharPreparationData['schema'] ?? null) !== 1
            || ($pharPreparationData['version'] ?? null) !== '1.0.1'
            || count($pharPreparationData['file_changes'] ?? []) !== 4
        ) {
            throw new RuntimeException('PHAR release preparation fixture did not produce ReleasePreparation v1.');
        }
    } finally {
        $server->stop(1);
    }
} finally {
    $cleanup = new Process(['rm', '-rf', $root]);
    $cleanup->run();
}

exit(0);
