<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Version;

use DomainException;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Domain\Version\VersionTransitionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTransitionPolicyTest extends TestCase
{
    #[DataProvider('forwardTransitionProvider')]
    public function testForwardTransitions(string $current, ReleaseChannel $channel, string $expected): void
    {
        $result = (new VersionTransitionPolicy())->transition(Version::parse($current), $channel);

        self::assertSame($expected, (string) $result);
        self::assertSame($channel->isPrerelease(), $channel !== ReleaseChannel::Final);
    }

    public static function forwardTransitionProvider(): iterable
    {
        yield 'repeat alpha increments' => ['16.0.0-alpha.2', ReleaseChannel::Alpha, '16.0.0-alpha.3'];
        yield 'alpha advances to beta' => ['16.0.0-alpha.3', ReleaseChannel::Beta, '16.0.0-beta.1'];
        yield 'repeat beta increments' => ['16.0.0-beta.2', ReleaseChannel::Beta, '16.0.0-beta.3'];
        yield 'beta advances to rc' => ['16.0.0-beta.2', ReleaseChannel::Rc, '16.0.0-rc.1'];
        yield 'repeat rc increments' => ['16.0.0-rc.3', ReleaseChannel::Rc, '16.0.0-rc.4'];
        yield 'rc finalizes' => ['16.0.0-rc.4', ReleaseChannel::Final, '16.0.0'];
        yield 'development starts alpha' => ['16.0.0-dev', ReleaseChannel::Alpha, '16.0.0-alpha.1'];
        yield 'numbered development starts alpha' => ['16.0.0-dev.2', ReleaseChannel::Alpha, '16.0.0-alpha.1'];
        yield 'development finalizes explicitly' => ['16.0.0-dev', ReleaseChannel::Final, '16.0.0'];
    }

    public function testRejectsBackwardTransition(): void
    {
        $this->expectException(DomainException::class);

        (new VersionTransitionPolicy())->transition(Version::parse('16.0.0-rc.1'), ReleaseChannel::Beta);
    }

    public function testRejectsRegressiveOverride(): void
    {
        $this->expectException(DomainException::class);

        (new VersionTransitionPolicy())->validateOverride(
            Version::parse('16.0.1'),
            Version::parse('16.0.0'),
            ReleaseChannel::Final,
        );
    }
}
