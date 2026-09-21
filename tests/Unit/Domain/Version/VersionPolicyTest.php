<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Domain\Version;

use LibreCode\ReleaseTool\Domain\Release\ReleaseActivity;
use LibreCode\ReleaseTool\Domain\Release\ReleaseItem;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Domain\Version\VersionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionPolicyTest extends TestCase
{
    #[DataProvider('proposalProvider')]
    public function testProposalUsesPullRequestTitleClassification(
        string $current,
        array $items,
        ReleaseChannel $channel,
        string $expected,
    ): void {
        $activity = new ReleaseActivity(array_map(
            static fn (array $item): ReleaseItem => new ReleaseItem(...$item),
            $items,
        ));

        $result = (new VersionPolicy())->propose(Version::parse($current), $activity, $channel);

        self::assertSame($expected, (string) $result);
    }

    public static function proposalProvider(): iterable
    {
        yield 'fix PR stays patch even if implementation may contain feat commit' => [
            '15.1.3',
            [['pull_request', 'fix: correct signature parsing', 10, 'fix']],
            ReleaseChannel::Final,
            '15.1.4',
        ];
        yield 'feature PR promotes minor' => [
            '15.1.3',
            [['pull_request', 'feat: add visible signatures', 11, 'feat']],
            ReleaseChannel::Final,
            '15.2.0',
        ];
        yield 'translation-only release is patch' => [
            '15.1.3',
            [['translation', 'Update translations']],
            ReleaseChannel::Final,
            '15.1.4',
        ];
        yield 'dependency-only release is patch' => [
            '15.1.3',
            [['dependency', 'chore(deps): update dependencies', 12, 'chore']],
            ReleaseChannel::Final,
            '15.1.4',
        ];
        yield 'feature prerelease starts from bumped minor' => [
            '15.1.3',
            [['pull_request', 'feat: add visible signatures', 11, 'feat']],
            ReleaseChannel::Alpha,
            '15.2.0-alpha.1',
        ];
    }
}
