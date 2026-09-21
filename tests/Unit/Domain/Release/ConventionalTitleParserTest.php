<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Release;

use LibreCode\ReleaseTool\Domain\Release\ConventionalTitleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConventionalTitleParserTest extends TestCase
{
    #[DataProvider('titleProvider')]
    public function testParsesStableAndBackportWrappers(
        string $title,
        ?string $type,
        ?string $scope,
        string $subject,
        bool $breaking,
    ): void {
        $parsed = (new ConventionalTitleParser())->parse($title);

        self::assertSame($type, $parsed->type);
        self::assertSame($scope, $parsed->scope);
        self::assertSame($subject, $parsed->subject);
        self::assertSame($breaking, $parsed->breaking);
    }

    public static function titleProvider(): iterable
    {
        yield 'feature' => ['feat(sign): add policy', 'feat', 'sign', 'add policy', false];
        yield 'breaking marker only warns' => ['feat(api)!: change response', 'feat', 'api', 'change response', true];
        yield 'stable wrapper' => ['[stable35] fix: avoid regression', 'fix', null, 'avoid regression', false];
        yield 'backport wrapper' => ['backport: feat: add policy', 'feat', null, 'add policy', false];
        yield 'legacy title remains activity' => ['Update translations', null, null, 'Update translations', false];
    }
}
