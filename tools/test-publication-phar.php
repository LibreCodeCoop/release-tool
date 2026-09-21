<?php

declare(strict_types=1);

use Phar;
use PharData;
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

$root = sys_get_temp_dir() . '/release-tool-publication-phar-' . bin2hex(random_bytes(8));
mkdir($root, 0777, true);
mkdir($root . '/appinfo', 0777, true);
mkdir($root . '/docs/changelogs', 0777, true);

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException("Could not allocate local API port: {$error}");
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
$sha = str_repeat('a', 40);

try {
    file_put_contents($root . '/appinfo/info.xml', '<info><id>example</id><version>1.0.0</version></info>');
    file_put_contents($root . '/package.json', "{\"version\":\"1.0.0\"}\n");
    file_put_contents($root . '/package-lock.json', "{\"version\":\"1.0.0\"}\n");
    file_put_contents(
        $root . '/docs/changelogs/changelog-1.md',
        "# Changelog\n\n## 1.0.0 - 2026-09-21\n\n### Fixed\n\n- Publication parity.\n",
    );

    file_put_contents($root . '/.nextcloud-release.yml', <<<YAML
schema: 1
repository: Example/app
app:
  id: example
  main_branch: main
branches:
  stable_pattern: '^stable(?<nextcloud>\\d+)$'
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
  required_paths:
    - appinfo
publication:
  publisher_workflow: appstore-build-publish.yml
  asset_name: '{app}-{tag}.tar.gz'
  appstore_api: http://127.0.0.1:{$port}/api/v1/apps.json
YAML);

    $git = static fn (array $args): Process => $run(['git', ...$args], $root);
    $git(['init', '-b', 'stable35']);
    $git(['config', 'user.name', 'Release Tool PHAR Test']);
    $git(['config', 'user.email', 'release-tool@example.invalid']);
    $git(['remote', 'add', 'origin', 'https://github.com/Example/app.git']);
    $git(['add', '.']);
    $git(['commit', '-m', 'chore: publication verification fixture']);

    $tarPath = $root . '/example-v1.0.0.tar';
    $archive = new PharData($tarPath);
    $archive->addFromString('example/appinfo/info.xml', '<info><id>example</id><version>1.0.0</version></info>');
    $archive->addFromString(
        'example/CHANGELOG.md',
        "# Changelog\n\n## 1.0.0 - 2026-09-21\n\n### Fixed\n\n- Publication parity.\n",
    );
    $archive->addFromString('example/appinfo/routes.php', '<?php');
    $archive->compress(Phar::GZ);
    unset($archive);
    @unlink($tarPath);
    $artifactPath = $tarPath . '.gz';

    $prepared = [
        'schema' => 1,
        'id' => 'prepared-publication-phar',
        'release_plan_id' => 'plan-publication-phar',
        'release_preparation_id' => 'preparation-publication-phar',
        'repository' => 'Example/app',
        'branch' => 'stable35',
        'release_pull_request' => [
            'number' => 1,
            'url' => 'https://example.test/pull/1',
        ],
        'final_sha' => $sha,
        'version' => '1.0.0',
        'tag_name' => 'v1.0.0',
        'channel' => 'final',
        'mode' => 'normal',
        'changelog' => [
            'target' => 'docs/changelogs/changelog-1.md',
            'section' => '## 1.0.0 - 2026-09-21',
            'sha256' => str_repeat('b', 64),
        ],
        'release_files' => [],
        'history_synchronization' => [
            'state' => 'already_synchronized',
            'target_branch' => 'main',
            'target_path' => 'docs/changelogs/changelog-1.md',
            'generated_branch' => null,
            'pull_request' => null,
        ],
    ];
    $draft = [
        'schema' => 1,
        'id' => 'draft-publication-phar',
        'prepared_release_id' => 'prepared-publication-phar',
        'milestone_transition_id' => 'milestone-publication-phar',
        'github_release' => [
            'id' => 101,
            'url' => 'https://example.test/releases/101',
        ],
        'tag_name' => 'v1.0.0',
        'target_sha' => $sha,
        'prerelease' => false,
        'body_sha256' => str_repeat('c', 64),
        'draft' => true,
        'ready' => true,
    ];
    file_put_contents(
        $root . '/prepared.json',
        json_encode($prepared, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
    file_put_contents(
        $root . '/draft.json',
        json_encode($draft, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );

    $router = <<<'PHP'
<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/repos/Example/app/releases/101') {
    echo json_encode([
        'id' => 101,
        'html_url' => 'https://example.test/releases/101',
        'tag_name' => 'v1.0.0',
        'target_commitish' => '__SHA__',
        'draft' => false,
        'prerelease' => false,
        'published_at' => '2026-09-21T18:00:00Z',
        'assets' => [[
            'id' => 303,
            'name' => 'example-v1.0.0.tar.gz',
            'url' => 'https://example.test/assets/303',
            'digest' => 'sha256:' . hash_file('sha256', __DIR__ . '/example-v1.0.0.tar.gz'),
        ]],
    ]);
    return;
}
if ($path === '/repos/Example/app/actions/workflows/appstore-build-publish.yml/runs') {
    echo json_encode([
        'workflow_runs' => [[
            'id' => 202,
            'html_url' => 'https://example.test/actions/runs/202',
            'head_sha' => '__SHA__',
            'event' => 'release',
            'status' => 'completed',
            'conclusion' => 'success',
            'created_at' => '2026-09-21T18:01:00Z',
        ]],
    ]);
    return;
}
if ($path === '/repos/Example/app/releases/assets/303') {
    header('Content-Type: application/octet-stream');
    readfile(__DIR__ . '/example-v1.0.0.tar.gz');
    return;
}
if ($path === '/api/v1/apps.json') {
    echo json_encode([[
        'id' => 'example',
        'releases' => [[
            'version' => '1.0.0',
            'isNightly' => false,
        ]],
    ]]);
    return;
}

http_response_code(404);
echo json_encode(['message' => 'Not Found']);
PHP;
    $router = str_replace('__SHA__', $sha, $router);
    file_put_contents($root . '/router.php', $router);

    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $root . '/router.php'], $root);
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
        throw new RuntimeException('Local publication API did not start.');
    }

    try {
        $arguments = [
            'publication:verify',
            '--draft', $root . '/draft.json',
            '--prepared', $root . '/prepared.json',
            '--config', $root . '/.nextcloud-release.yml',
            '--root', $root,
            '--format', 'json',
        ];
        $environment = [
            'GITHUB_API_URL' => "http://127.0.0.1:{$port}",
            'GITHUB_REPOSITORY' => 'Example/app',
        ];

        $source = $run(
            [PHP_BINARY, dirname(__DIR__) . '/bin/release-tool', ...$arguments],
            $root,
            $environment,
        );
        $packaged = $run(
            [PHP_BINARY, $phar, ...$arguments],
            $root,
            $environment,
        );

        $sourceData = json_decode($source->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $pharData = json_decode($packaged->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        unset($sourceData['verified_at'], $pharData['verified_at']);

        if ($sourceData !== $pharData) {
            throw new RuntimeException('Source CLI and PHAR publication verification differ.');
        }
        if (
            ($pharData['schema'] ?? null) !== 1
            || ($pharData['success'] ?? null) !== true
            || ($pharData['publisher']['success'] ?? null) !== true
            || ($pharData['artifact']['valid'] ?? null) !== true
            || ($pharData['appstore']['visible'] ?? null) !== true
        ) {
            throw new RuntimeException('PHAR publication verification did not produce successful PublicationVerification v1.');
        }
    } finally {
        $server->stop(1);
    }
} finally {
    $cleanup = new Process(['rm', '-rf', $root]);
    $cleanup->run();
}

exit(0);
