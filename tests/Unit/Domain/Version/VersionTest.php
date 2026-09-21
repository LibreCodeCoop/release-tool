<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Version;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    #[DataProvider('validVersionProvider')]
    public function testParsesAndNormalizes(string $input, string $expected): void
    {
        self::assertSame($expected, (string) Version::parse($input));
    }

    public static function validVersionProvider(): iterable
    {
        yield 'final' => ['16.2.3', '16.2.3'];
        yield 'tag prefix accepted' => ['v16.2.3', '16.2.3'];
        yield 'alpha' => ['16.0.0-alpha.1', '16.0.0-alpha.1'];
        yield 'beta' => ['16.0.0-beta.2', '16.0.0-beta.2'];
        yield 'rc' => ['16.0.0-rc.4', '16.0.0-rc.4'];
        yield 'development' => ['16.0.0-dev', '16.0.0-dev'];
    }

    public function testRejectsInvalidVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Version::parse('16.0');
    }
}
