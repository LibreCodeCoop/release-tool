<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\ReleaseDraftCodec;
use PHPUnit\Framework\TestCase;

final class ReleaseDraftCodecTest extends TestCase
{
    public function testDecodesCommittedV1Fixture(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Release/release-draft-v1.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json);
        $draft = (new ReleaseDraftCodec())->decode($json);
        self::assertSame('draft-fixture-id', $draft->id);
        self::assertSame('prepared-fixture-id', $draft->preparedReleaseId);
        self::assertSame('milestone-fixture-id', $draft->milestoneTransitionId);
        self::assertSame(123, $draft->releaseId);
        self::assertSame('v15.0.4', $draft->tagName);
        self::assertTrue($draft->draft);
        self::assertTrue($draft->ready);
    }
}
