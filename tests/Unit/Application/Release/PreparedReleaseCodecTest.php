<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Release\PreparedReleaseCodec;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use PHPUnit\Framework\TestCase;

final class PreparedReleaseCodecTest extends TestCase
{
    public function testRejectsNumericReleaseFileKeyInJsonObject(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Release/prepared-release-v1.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['release_files']['42'] = str_repeat('0', 64);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('release_files must have string keys.');

        (new PreparedReleaseCodec())->decode((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testDecodesCommittedV1Fixture(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Release/prepared-release-v1.json';
        $json = file_get_contents($path);
        self::assertNotFalse($json);

        $prepared = (new PreparedReleaseCodec())->decode($json);

        self::assertSame('prepared-fixture-id', $prepared->id);
        self::assertSame('v15.0.4', $prepared->tagName);
        self::assertSame(9001, $prepared->releasePullRequestNumber);
        self::assertSame(HistorySyncState::PullRequestOpen, $prepared->historySynchronization->state);
        self::assertSame(9002, $prepared->historySynchronization->pullRequestNumber);
    }
}
