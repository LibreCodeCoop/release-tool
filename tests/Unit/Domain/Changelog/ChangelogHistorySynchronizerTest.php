<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Changelog;

use DomainException;
use LibreCode\ReleaseTool\Domain\Changelog\ChangelogHistorySynchronizer;
use PHPUnit\Framework\TestCase;

final class ChangelogHistorySynchronizerTest extends TestCase
{
    public function testPrependsExactStableSectionWithoutRegeneration(): void
    {
        $current = "# Changelog\n\n## 15.0.3 - 2026-09-20\n\n### Fixed\n\n- Previous.\n";
        $section = "## 15.0.4 - 2026-09-21\n\n### Fixed\n\n- Human-edited wording (#77)";

        $result = (new ChangelogHistorySynchronizer())->synchronize($current, '15.0.4', $section);

        self::assertStringContainsString($section, $result);
        self::assertLessThan(
            strpos($result, '## 15.0.3'),
            strpos($result, '## 15.0.4'),
        );
    }

    public function testRerunIsNoopWhenExactSectionAlreadyExists(): void
    {
        $current = "# Changelog\n\n## 15.0.4 - 2026-09-21\n\n- Exact.\n\n## 15.0.3 - 2026-09-20\n\n- Previous.\n";
        $section = "## 15.0.4 - 2026-09-21\n\n- Exact.";

        self::assertSame(
            $current,
            (new ChangelogHistorySynchronizer())->synchronize($current, '15.0.4', $section),
        );
    }

    public function testConflictingExistingSectionFailsClosed(): void
    {
        $current = "# Changelog\n\n## 15.0.4 - 2026-09-21\n\n- Different.\n";

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('different content');

        (new ChangelogHistorySynchronizer())->synchronize(
            $current,
            '15.0.4',
            "## 15.0.4 - 2026-09-21\n\n- Exact.",
        );
    }
}
