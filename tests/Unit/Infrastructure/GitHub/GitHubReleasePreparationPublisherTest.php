<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Domain\Release\FileChange;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Infrastructure\GitHub\GitHubReleasePreparationPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubReleasePreparationPublisherTest extends TestCase
{
    private const BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCreatesGeneratedBranchAndPullRequest(): void
    {
        $responses = [
            $this->json(['object' => ['sha' => self::BASE]]),
            $this->json(['tree' => ['sha' => 'base-tree']]),
            $this->json(['sha' => 'tree-sha']),
            $this->json(['message' => 'Not Found'], 404),
            $this->json(['sha' => 'commit-sha']),
            $this->json(['ref' => 'refs/heads/release-tool/stable35/15.0.4/plan']),
            $this->json([]),
            $this->json(['number' => 42, 'html_url' => 'https://example.test/pr/42'], 201),
        ];
        $requestBodies = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$responses, &$requestBodies): MockResponse {
                if ($method === 'POST' && str_ends_with($url, '/pulls')) {
                    $requestBodies[] = json_decode((string) ($options['body'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
                }

                return array_shift($responses);
            },
            'https://api.github.test',
        );

        $result = (new GitHubReleasePreparationPublisher(
            'token',
            $client,
            'https://api.github.test',
            'vitormattos',
        ))->publish($this->preparation());

        self::assertSame(42, $result->pullRequestNumber);
        self::assertSame('https://example.test/pr/42', $result->pullRequestUrl);
        self::assertStringContainsString(
            'Requested by @vitormattos via [Prepare release](https://github.com/LibreCodeCoop/release-tool)',
            (string) ($requestBodies[0]['body'] ?? ''),
        );
        self::assertSame(8, $client->getRequestsCount());
    }

    public function testRerunReusesMatchingGeneratedBranchAndPullRequest(): void
    {
        $preparation = $this->preparation();
        $responses = [
            $this->json(['object' => ['sha' => self::BASE]]),
            $this->json(['tree' => ['sha' => 'base-tree']]),
            $this->json(['sha' => 'tree-sha']),
            $this->json(['object' => ['sha' => 'commit-sha']]),
            $this->json(['tree' => ['sha' => 'tree-sha']]),
            $this->json([[
                'number' => 42,
                'html_url' => 'https://example.test/pr/42',
                'body' => $preparation->prMarker . "\nexisting body",
            ]]),
        ];
        $client = new MockHttpClient($responses, 'https://api.github.test');

        $result = (new GitHubReleasePreparationPublisher('token', $client, 'https://api.github.test'))
            ->publish($preparation);

        self::assertSame(42, $result->pullRequestNumber);
        self::assertSame(6, $client->getRequestsCount());
    }

    public function testRefusesToOverwriteManuallyEditedGeneratedBranch(): void
    {
        $responses = [
            $this->json(['object' => ['sha' => self::BASE]]),
            $this->json(['tree' => ['sha' => 'base-tree']]),
            $this->json(['sha' => 'tree-sha']),
            $this->json(['object' => ['sha' => 'edited-commit']]),
            $this->json(['tree' => ['sha' => 'different-tree']]),
        ];
        $client = new MockHttpClient($responses, 'https://api.github.test');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('manual edits will not be overwritten');

        (new GitHubReleasePreparationPublisher('token', $client, 'https://api.github.test'))
            ->publish($this->preparation());
    }

    private function preparation(): ReleasePreparation
    {
        return new ReleasePreparation(
            'prep-id',
            'plan-id',
            'LibreSign/libresign',
            'stable35',
            self::BASE,
            '15.0.4',
            ReleaseChannel::Final,
            ReleaseMode::Normal,
            [new FileChange(
                'appinfo/info.xml',
                hash('sha256', 'before'),
                hash('sha256', 'after'),
                'after',
            )],
            "## 15.0.4 - 2026-09-20\n\n### Fixed\n\n- Fix.",
            hash('sha256', 'section'),
            'release-tool/stable35/15.0.4/plan',
            '<!-- release-tool:preparation plan=plan-id version=15.0.4 -->',
            null,
            null,
        );
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
