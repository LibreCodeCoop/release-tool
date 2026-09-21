<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Changelog;

use LibreCode\ReleaseTool\Domain\Changelog\ChangelogMigrator;
use PHPUnit\Framework\TestCase;

final class ChangelogMigratorTest extends TestCase
{
    public function testSplitsHistoryAcrossThreeMajorsWithoutLosingSections(): void
    {
        $input = <<<'MD'
# Changelog

## 16.0.0 - 2026-09-01

### Added

- Sixteen.

## 15.2.0 - 2026-08-01

### Fixed

- Fifteen two.

## 15.1.0 - 2026-07-01

### Fixed

- Fifteen one.

## 14.5.0 - 2026-06-01

### Changed

- Fourteen.
MD;

        $result = (new ChangelogMigrator())->splitByMajor($input);

        self::assertSame([16, 15, 14], array_keys($result));
        self::assertStringContainsString('16.0.0', $result[16]);
        self::assertStringContainsString('15.2.0', $result[15]);
        self::assertStringContainsString('15.1.0', $result[15]);
        self::assertStringContainsString('14.5.0', $result[14]);
        self::assertStringNotContainsString('16.0.0', $result[15]);
    }
}
