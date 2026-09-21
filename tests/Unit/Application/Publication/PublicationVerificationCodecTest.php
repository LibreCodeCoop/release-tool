<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Publication;

use LibreCode\ReleaseTool\Application\Publication\PublicationVerificationCodec;
use PHPUnit\Framework\TestCase;

final class PublicationVerificationCodecTest extends TestCase
{
    public function testRoundTripsVersionedFixture(): void
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/Release/publication-verification-v1.json';
        $json = (string) file_get_contents($path);
        $codec = new PublicationVerificationCodec();
        $decoded = $codec->decode($json);
        $encoded = $codec->encode($decoded);
        $roundTrip = $codec->decode($encoded);

        self::assertSame($decoded->jsonSerialize(), $roundTrip->jsonSerialize());
        self::assertTrue($roundTrip->success);
        self::assertSame(202, $roundTrip->publisherRunId);
    }
}
