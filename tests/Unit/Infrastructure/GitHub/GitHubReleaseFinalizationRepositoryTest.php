<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Infrastructure\GitHub;

use LibreCode\ReleaseTool\Application\Release\HistorySyncRequest;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Infrastructure\GitHub\GitHubReleaseFinalizationRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubReleaseFinalizationRepositoryTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testReadsMergedPullRequestAndChangedFiles(): void
    {
        $client = new MockHttpClient([
            $this->json([
                'merged_at' => '2026-09-21T10:00:00Z',
                'merge_commit_sha' => self::SHA,
                'base' => ['ref' => 'stable35'],
                'html_url' => 'https://github.com/LibreSign/libresign/pull/77',
            ]),
            $this->json([
                ['filename' => 'package.json'],
                ['filename' => 'appinfo/info.xml'],
            ]),
        ], 'https://api.github.test');

        $result = (new GitHubReleaseFinalizationRepository('token', $client, 'https://api.github.test'))
            ->pullRequest('LibreSign/libresign', 77);

        self::assertTrue($result->merged);
        self::assertSame(self::SHA, $result->mergeCommitSha);
        self::assertSame(['appinfo/info.xml', 'package.json'], $result->changedFiles);
    }

    public function testPublishesHistoryThroughGeneratedBranchAndPullRequest(): void
    {
        $client = new MockHttpClient([
            $this->json(['object' => ['sha' => self::SHA]]),
            $this->json(['tree' => ['sha' => 'base-tree']]),
            $this->json(['sha' => 'new-tree']),
            $this->json(['message' => 'Not Found'], 404),
            $this->json(['sha' => 'new-commit']),
            $this->json(['ref' => 'refs/heads/release-tool/history/15.0.4/prepared']),
            $this->json([]),
            $this->json([
                'number' => 88,
                'html_url' => 'https://github.com/LibreSign/libresign/pull/88',
            ], 201),
        ], 'https://api.github.test');

        $result = (new GitHubReleaseFinalizationRepository('token', $client, 'https://api.github.test'))
            ->publishHistorySynchronization(new HistorySyncRequest(
                'LibreSign/libresign',
                'main',
                self::SHA,
                'docs/changelogs/changelog-15.md',
                "# Changelog\n\n## 15.0.4 - 2026-09-21\n",
                'release-tool/history/15.0.4/prepared',
                '<!-- release-tool:history prepared=id version=15.0.4 -->',
                '15.0.4',
            ));

        self::assertSame(HistorySyncState::PullRequestOpen, $result->state);
        self::assertSame(88, $result->pullRequestNumber);
        self::assertSame('https://github.com/LibreSign/libresign/pull/88', $result->pullRequestUrl);
    }

    /** @param array<mixed> $data */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type: application/json']],
        );
    }
}
