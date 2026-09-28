<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use LibreCode\ReleaseTool\Application\Release\MilestoneNamingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MilestoneNamingPolicyTest extends TestCase
{
    #[DataProvider('titleMatchProvider')]
    public function testMatchesLogicalMilestoneTitles(string $expected, string $actual, bool $matches): void
    {
        self::assertSame($matches, (new MilestoneNamingPolicy())->matches($expected, $actual));
    }

    /** @return iterable<string, array{string,string,bool}> */
    public static function titleMatchProvider(): iterable
    {
        yield 'exact title' => ['Next Patch (35)', 'Next Patch (35)', true];
        yield 'leading emoji' => ['Next Patch (35)', '💚 Next Patch (35)', true];
        yield 'trailing emoji' => ['Next Patch (35)', 'Next Patch (35) 💚', true];
        yield 'emoji on both sides' => ['Next Patch (35)', '🚀 Next Patch (35) 💚', true];
        yield 'extra surrounding whitespace' => ['Next Patch (35)', '  Next Patch (35)  ', true];
        yield 'collapsed internal whitespace' => ['Next Patch (35)', 'Next   Patch (35)', true];
        yield 'different nextcloud line' => ['Next Patch (35)', '💚 Next Patch (34)', false];
        yield 'different release kind' => ['Next Patch (35)', '💚 Next RC (35)', false];
        yield 'semantic suffix remains significant' => ['Next Patch (35)', 'Next Patch (35) urgent', false];
    }
}
