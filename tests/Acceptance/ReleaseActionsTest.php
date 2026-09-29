<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReleaseActionsTest extends TestCase
{
    private const array PUBLIC_ACTIONS = ['appstore-publication-wait', 'artifact-validate', 'metadata-inspect', 'post-merge', 'prepare', 'publication', 'release-identity', 'release-notes', 'release-preflight', 'resume-context', 'stable-select'];

    public function testPublicActionSurfaceIsExplicit(): void
    {
        $paths = glob($this->root() . '/actions/*/action.yml');
        self::assertIsArray($paths);

        $names = array_map(
            static fn (string $path): string => basename(dirname($path)),
            $paths,
        );
        sort($names, SORT_STRING);

        self::assertSame(self::PUBLIC_ACTIONS, $names);
    }

    #[DataProvider('publicActions')]
    public function testPublicActionsAreReleaseToolOrchestrationWithoutLegacyHelpers(string $name): void
    {
        $path = $this->root() . '/actions/' . $name . '/action.yml';
        $content = (string) file_get_contents($path);
        $action = Yaml::parseFile($path);

        self::assertIsArray($action);
        self::assertSame('composite', $action['runs']['using'] ?? null);
        self::assertStringNotContainsString('python3', $content);
        self::assertStringNotContainsString('LibreCodeCoop/github-workflows', $content);
        self::assertStringNotContainsString('$/actions/', $content);

        self::assertStringContainsString('../_internal/setup.sh', $content);
    }

    #[DataProvider('publicActionContracts')]
    public function testPublicActionContractsAreExplicit(
        string $name,
        array $expectedInputs,
        array $expectedOutputs,
    ): void {
        $action = Yaml::parseFile($this->root() . '/actions/' . $name . '/action.yml');
        self::assertIsArray($action);

        $inputs = array_keys(is_array($action['inputs'] ?? null) ? $action['inputs'] : []);
        $outputs = array_keys(is_array($action['outputs'] ?? null) ? $action['outputs'] : []);

        self::assertSame($expectedInputs, $inputs);
        self::assertSame($expectedOutputs, $outputs);
    }

    public function testPrepareDelegatesAuthorizationAndPlanningToPhp(): void
    {
        $content = $this->action('prepare');
        $action = Yaml::parse($content);
        self::assertIsArray($action);

        $steps = $action['runs']['steps'] ?? [];
        self::assertIsArray($steps);

        $planStep = null;
        foreach ($steps as $step) {
            if (is_array($step) && ($step['id'] ?? null) === 'plan') {
                $planStep = $step;
                break;
            }
        }
        self::assertIsArray($planStep);

        $planRun = $planStep['run'] ?? null;
        self::assertIsString($planRun);

        self::assertStringContainsString('release:plan', $planRun);
        self::assertStringContainsString('--github-annotations', $planRun);
        self::assertStringContainsString('--github-step-summary', $planRun);
        self::assertStringNotContainsString('Release plan is not ready', $planRun);
        self::assertStringNotContainsString('Open backport blocker', $planRun);
        self::assertStringNotContainsString('json_decode(', $planRun);

        self::assertStringContainsString('release:authorization', $content);
        self::assertStringContainsString('release:prepare', $content);
    }

    public function testPostMergeDelegatesRestoreAuthorizationAndFinalizationToPhp(): void
    {
        $content = $this->action('post-merge');

        self::assertStringContainsString('artifact:restore', $content);
        self::assertStringContainsString('release:authorization', $content);
        self::assertStringContainsString('release:finalize', $content);
        self::assertStringContainsString('milestone:transition', $content);
        self::assertStringContainsString('cat "${RELEASE_STATE_DIR}/milestone-transition.json" || true', $content);
        self::assertStringContainsString('release:draft', $content);
        self::assertStringContainsString('use-caller-token-for-draft', $content);
        self::assertStringContainsString("inputs.use-caller-token-for-draft == 'true'", $content);
    }

    public function testReleaseIdentityDelegatesValidationToPhp(): void
    {
        $content = $this->action('release-identity');

        self::assertStringContainsString('release:validate-identity', $content);
        self::assertStringNotContainsString('=~', $content);
        self::assertStringNotContainsString('git ls-remote', $content);
    }

    public function testPublicationDefaultsToPullRequestTargetPostMergeEvent(): void
    {
        $action = Yaml::parseFile($this->root() . '/actions/publication/action.yml');
        self::assertIsArray($action);

        self::assertSame(
            'pull_request_target',
            $action['inputs']['post-merge-event']['default'] ?? null,
        );
    }

    public function testPublicationDelegatesRestoreAndVerificationToPhp(): void
    {
        $content = $this->action('publication');

        self::assertStringContainsString('artifact:restore', $content);
        self::assertStringContainsString('publication:verify', $content);
        self::assertStringContainsString('workflow_dispatch', $content);
        self::assertStringContainsString('resume-workflow-path', $content);
    }

    public function testInternalBootstrapUsesExactVersionAndVerifiedChecksum(): void
    {
        $version = trim((string) file_get_contents($this->root() . '/VERSION'));
        $setup = (string) file_get_contents($this->root() . '/actions/_internal/setup.sh');

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:[.-][0-9A-Za-z.-]+)?$/', $version);
        self::assertStringContainsString('repository_root', $setup);
        self::assertStringContainsString('/VERSION', $setup);
        self::assertStringContainsString('release-tool.phar.sha256', $setup);
        self::assertStringContainsString('sha256sum', $setup);
        self::assertStringContainsString("--proto '=https'", $setup);
        self::assertStringNotContainsString('latest', $version);
        self::assertStringNotContainsString('stable', $version);
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> */
    public static function publicActionContracts(): iterable
    {
        yield 'appstore-publication-wait' => [
            'appstore-publication-wait',
            ['app-name', 'version', 'platform', 'attempts', 'delay-seconds'],
            [],
        ];
        yield 'artifact-validate' => [
            'artifact-validate',
            ['artifact', 'app-name', 'version'],
            [],
        ];
        yield 'metadata-inspect' => [
            'metadata-inspect',
            ['config-path', 'root', 'ref'],
            ['changelog-path', 'version', 'major', 'development'],
        ];
        yield 'prepare' => [
            'prepare',
            [
                'branch',
                'ref',
                'version',
                'channel',
                'ignore-open-backport',
                'create-follow-up-milestone',
                'config-path',
                'actor',
                'github-token',
                'app-slug',
                'app-private-key',
            ],
            [
                'preparation-id',
                'pull-request-number',
                'pull-request-url',
                'artifact-name',
                'tool-version',
            ],
        ];
        yield 'post-merge' => [
            'post-merge',
            [
                'pull-request-number',
                'merger',
                'config-path',
                'prepare-workflow-path',
                'github-token',
                'app-slug',
                'app-private-key',
                'use-caller-token-for-draft',
            ],
            [
                'prepared-release-id',
                'release-draft-id',
                'github-release-id',
                'github-release-url',
                'state-artifact-name',
            ],
        ];
        yield 'publication' => [
            'publication',
            [
                'github-release-id',
                'config-path',
                'post-merge-workflow-path',
                'post-merge-event',
                'resume-workflow-path',
                'attempts',
                'delay-seconds',
                'github-token',
            ],
            [
                'verification-id',
                'verification-artifact-name',
            ],
        ];
        yield 'release-identity' => [
            'release-identity',
            ['tag', 'working-directory', 'config-path', 'ref', 'require-tag-exists'],
            ['tag', 'version', 'sha'],
        ];
        yield 'release-notes' => [
            'release-notes',
            ['repository', 'branch', 'working-directory', 'from-ref', 'to-ref', 'fallback-limit', 'github-token'],
            ['changes-file', 'change-count', 'pull-request-count', 'commit-fallback-count'],
        ];
        yield 'release-preflight' => [
            'release-preflight',
            ['version', 'stable-branch', 'current-ref', 'repository', 'appinfo', 'changelog', 'milestone', 'blocker-queries-json', 'github-token'],
            ['result-file'],
        ];
        yield 'resume-context' => [
            'resume-context',
            ['pull-request-number', 'github-token'],
            ['base-ref', 'merger'],
        ];
        yield 'stable-select' => [
            'stable-select',
            ['repository', 'branch', 'github-token'],
            ['is-latest', 'current-branch', 'current-major', 'latest-branch', 'latest-major'],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function publicActions(): iterable
    {
        foreach (self::PUBLIC_ACTIONS as $name) {
            yield $name => [$name];
        }
    }

    private function action(string $name): string
    {
        return (string) file_get_contents($this->root() . '/actions/' . $name . '/action.yml');
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
