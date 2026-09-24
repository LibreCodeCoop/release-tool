<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReleaseActionsTest extends TestCase
{
    private const array PUBLIC_ACTIONS = ['post-merge', 'prepare', 'publication'];

    public function testPublicActionSurfaceContainsOnlyLifecycleStages(): void
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
    public function testPublicActionsArePhpOrchestrationWithoutLegacyHelpers(string $name): void
    {
        $path = $this->root() . '/actions/' . $name . '/action.yml';
        $content = (string) file_get_contents($path);
        $action = Yaml::parseFile($path);

        self::assertIsArray($action);
        self::assertSame('composite', $action['runs']['using'] ?? null);
        self::assertStringNotContainsString('python3', $content);
        self::assertStringNotContainsString('LibreCodeCoop/github-workflows', $content);
        self::assertStringNotContainsString('$/actions/', $content);

        foreach ([
            'release-tool-setup',
            'release-plan',
            'release-authorization',
            'release-consumer-validate',
            'release-artifact-restore',
            'release-artifact-validate',
            'release-stable-select',
            'release-notes-from-pull-requests',
        ] as $legacyAction) {
            self::assertStringNotContainsString($legacyAction, $content);
        }

        self::assertStringContainsString('../_internal/setup.sh', $content);
    }

    public function testPrepareDelegatesAuthorizationAndPlanningToPhp(): void
    {
        $content = $this->action('prepare');

        self::assertStringContainsString('release:plan', $content);
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
        self::assertStringContainsString('release:draft', $content);
    }

    public function testPublicationDelegatesRestoreAndVerificationToPhp(): void
    {
        $content = $this->action('publication');

        self::assertStringContainsString('artifact:restore', $content);
        self::assertStringContainsString('publication:verify', $content);
    }

    public function testInternalBootstrapUsesExactVersionAndVerifiedChecksum(): void
    {
        $version = trim((string) file_get_contents($this->root() . '/actions/_internal/release-tool-version'));
        $setup = (string) file_get_contents($this->root() . '/actions/_internal/setup.sh');

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:[.-][0-9A-Za-z.-]+)?$/', $version);
        self::assertStringContainsString('release-tool.phar.sha256', $setup);
        self::assertStringContainsString('sha256sum', $setup);
        self::assertStringContainsString("--proto '=https'", $setup);
        self::assertStringNotContainsString('latest', $version);
        self::assertStringNotContainsString('stable', $version);
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
