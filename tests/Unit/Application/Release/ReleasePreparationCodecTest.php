<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\ReleasePreparationCodec;
use PHPUnit\Framework\TestCase;

final class ReleasePreparationCodecTest extends TestCase
{
    public function testDecodesCommittedV1FixtureWithoutFileContents(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Release/release-preparation-v1.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json);

        $preparation = (new ReleasePreparationCodec())->decode($json);

        self::assertSame('prep-fixture-id', $preparation->id);
        self::assertSame(9001, $preparation->pullRequestNumber);
        self::assertSame('stable35', $preparation->targetBranch);
        self::assertCount(2, $preparation->fileChanges);
        self::assertNull($preparation->fileChanges[0]->content);
    }
}
