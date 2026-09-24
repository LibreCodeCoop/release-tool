<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Acceptance;

use PHPUnit\Framework\TestCase;

final class VersioningPolicyTest extends TestCase
{
    public function testVersionFileContainsExactSemanticVersion(): void
    {
        $version = trim((string) file_get_contents($this->root() . '/VERSION'));

        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:[.-][0-9A-Za-z.-]+)?$/',
            $version,
        );
        self::assertSame('0.11.0', $version);
    }

    public function testReleasePublisherRequiresTagToMatchVersionFile(): void
    {
        $workflow = (string) file_get_contents($this->root() . '/.github/workflows/release.yml');

        self::assertStringContainsString('Verify release tag matches VERSION', $workflow);
        self::assertStringContainsString('< VERSION', $workflow);
        self::assertStringContainsString('"${TAG}" != "v${version}"', $workflow);
        self::assertStringContainsString('Verify embedded version', $workflow);
    }

    public function testVersioningPolicyDefinesWholeProductCompatibility(): void
    {
        $policy = (string) file_get_contents($this->root() . '/docs/versioning.md');

        self::assertStringContainsString('PHP CLI/PHAR', $policy);
        self::assertStringContainsString('public GitHub Actions', $policy);
        self::assertStringContainsString('persisted release contracts', $policy);
        self::assertStringContainsString('immutable commit SHA', $policy);
        self::assertStringContainsString('actions/_internal', $policy);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
