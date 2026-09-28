<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\ReadModel\FinalizedPullRequest;
use LibreCode\ReleaseTool\Application\Release\ReleaseResumeContextResolver;
use LibreCode\ReleaseTool\Tests\Fixtures\Release\InMemoryReleaseFinalizationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseResumeContextResolverTest extends TestCase
{
    public function testResolvesMergedReleasePreparationContext(): void
    {
        $resolver = new ReleaseResumeContextResolver(new InMemoryReleaseFinalizationRepository(
            $this->pullRequest(),
        ));

        $context = $resolver->resolve('LibreSign/libresign', 8815);

        self::assertSame(8815, $context->pullRequestNumber);
        self::assertSame('stable35', $context->baseBranch);
        self::assertSame('vitormattos', $context->merger);
    }

    #[DataProvider('invalidContextProvider')]
    public function testRejectsInvalidResumeContext(FinalizedPullRequest $pullRequest, string $message): void
    {
        $resolver = new ReleaseResumeContextResolver(new InMemoryReleaseFinalizationRepository($pullRequest));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);

        $resolver->resolve('LibreSign/libresign', 8815);
    }

    /** @return iterable<string, array{FinalizedPullRequest,string}> */
    public static function invalidContextProvider(): iterable
    {
        yield 'not merged' => [
            self::makePullRequest(merged: false),
            'PR #8815 is not merged.',
        ];
        yield 'wrong head branch' => [
            self::makePullRequest(headBranch: 'feature/example'),
            'PR #8815 is not a release-tool preparation PR.',
        ];
        yield 'missing marker' => [
            self::makePullRequest(body: 'ordinary body'),
            'PR #8815 does not contain a release-tool preparation marker.',
        ];
        yield 'missing merger' => [
            self::makePullRequest(mergedBy: null),
            'PR #8815 is missing merger metadata.',
        ];
    }

    private function pullRequest(): FinalizedPullRequest
    {
        return self::makePullRequest();
    }

    private static function makePullRequest(
        bool $merged = true,
        string $headBranch = 'release-tool/stable35/15.0.5/212b7e4070a7',
        string $body = '<!-- release-tool:preparation plan=abc version=15.0.5 -->',
        ?string $mergedBy = 'vitormattos',
    ): FinalizedPullRequest {
        return new FinalizedPullRequest(
            8815,
            'https://github.com/LibreSign/libresign/pull/8815',
            'stable35',
            $merged,
            str_repeat('a', 40),
            [],
            $headBranch,
            $body,
            $mergedBy,
        );
    }
}
