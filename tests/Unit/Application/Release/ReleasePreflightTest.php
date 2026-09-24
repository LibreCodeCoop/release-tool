<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\Port\ReleasePreflightRepository;
use LibreCode\ReleaseTool\Application\Release\ReleasePreflight;
use PHPUnit\Framework\TestCase;

final class ReleasePreflightTest extends TestCase
{
    public function testValidatesLocalFilesMilestoneAndBlockers(): void
    {
        $directory = sys_get_temp_dir() . '/release-preflight-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        file_put_contents($directory . '/info.xml', '<info><version>1.2.3</version></info>');
        file_put_contents($directory . '/CHANGELOG.md', "## 1.2.3 - 2026-09-24\n\n- Fixed release\n");

        $repository = new class implements ReleasePreflightRepository {
            public function milestone(string $repository, string $title): ?array
            {
                TestCase::assertSame('LibreCodeCoop/example', $repository);
                TestCase::assertSame('1.2.3', $title);
                return ['state' => 'closed', 'open_issues' => 0];
            }

            public function openItemCount(string $repository, string $query): int
            {
                TestCase::assertSame('LibreCodeCoop/example', $repository);
                TestCase::assertSame('label:blocker', $query);
                return 0;
            }
        };

        try {
            $result = (new ReleasePreflight($repository))->check(
                '1.2.3',
                'stable35',
                'stable35',
                'LibreCodeCoop/example',
                $directory . '/info.xml',
                $directory . '/CHANGELOG.md',
                '1.2.3',
                ['label:blocker'],
            );
        } finally {
            @unlink($directory . '/info.xml');
            @unlink($directory . '/CHANGELOG.md');
            @rmdir($directory);
        }

        self::assertTrue($result['ready']);
        self::assertCount(6, $result['checks']);
        self::assertSame(
            ['version', 'branch', 'appinfo', 'changelog', 'milestone', 'blocker:label:blocker'],
            array_column($result['checks'], 'name'),
        );
    }

    public function testReportsTheSameReadinessFailuresAsLegacyPlanner(): void
    {
        $directory = sys_get_temp_dir() . '/release-preflight-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        file_put_contents($directory . '/info.xml', '<info><version>1.2.2</version></info>');
        file_put_contents($directory . '/CHANGELOG.md', "## 1.2.2\n");

        $repository = new class implements ReleasePreflightRepository {
            public function milestone(string $repository, string $title): ?array
            {
                return ['state' => 'open', 'open_issues' => 2];
            }

            public function openItemCount(string $repository, string $query): int
            {
                return 3;
            }
        };

        try {
            $result = (new ReleasePreflight($repository))->check(
                '1.2',
                'stable35',
                'main',
                'LibreCodeCoop/example',
                $directory . '/info.xml',
                $directory . '/CHANGELOG.md',
                '1.2.3',
                ['label:blocker'],
            );
        } finally {
            @unlink($directory . '/info.xml');
            @unlink($directory . '/CHANGELOG.md');
            @rmdir($directory);
        }

        self::assertFalse($result['ready']);
        self::assertSame([false, false, false, false, false, false], array_column($result['checks'], 'ok'));
    }
}
