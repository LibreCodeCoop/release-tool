<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Security;

use LibreCode\ReleaseTool\Application\Security\PublicReleaseContext;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Security\SecurityReleasePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityReleasePolicyTest extends TestCase
{
    #[DataProvider('publicTextProvider')]
    public function testPublicTextPolicy(
        ReleaseMode $mode,
        ?string $explicit,
        string $expected,
        bool $isExplicit,
    ): void {
        $text = (new SecurityReleasePolicy())->publicText($mode, $explicit);

        self::assertSame($expected, $text->text);
        self::assertSame($isExplicit, $text->explicit);
    }

    public static function publicTextProvider(): iterable
    {
        yield 'normal neutral' => [ReleaseMode::Normal, null, 'Maintenance release.', false];
        yield 'security neutral' => [ReleaseMode::Security, null, 'This release includes security fixes.', false];
        yield 'security explicit safe text' => [ReleaseMode::Security, 'Security hardening and fixes.', 'Security hardening and fixes.', true];
    }

    public function testPrivateSensitiveInputCannotLeakThroughPublicSerialization(): void
    {
        $privateAdvisory = 'PRIVATE-ADVISORY-DO-NOT-PUBLISH';
        $policy = new SecurityReleasePolicy();
        $context = new PublicReleaseContext(
            ReleaseMode::Security,
            $policy->publicText(ReleaseMode::Security, null),
        );

        $json = json_encode($context, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($privateAdvisory, $json);
        self::assertStringContainsString('security', $json);
        self::assertStringContainsString(SecurityReleasePolicy::NEUTRAL_SECURITY_TEXT, $json);
    }
}
