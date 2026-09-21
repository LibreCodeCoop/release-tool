<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\MilestoneTransitionCodec;
use PHPUnit\Framework\TestCase;

final class MilestoneTransitionCodecTest extends TestCase
{
    public function testDecodesCommittedV1Fixture(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Release/milestone-transition-v1.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json);

        $transition = (new MilestoneTransitionCodec())->decode($json);

        self::assertSame('transition-fixture-id', $transition->id);
        self::assertSame('prepared-fixture-id', $transition->preparedReleaseId);
        self::assertSame(7, $transition->releasedMilestoneNumber);
        self::assertSame(8, $transition->followUpMilestoneNumber);
        self::assertSame(3, $transition->movedIssues);
        self::assertSame(1, $transition->movedPullRequests);
    }
}
