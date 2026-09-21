<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Integration\Infrastructure\GitHub;

use LibreCode\ReleaseTool\Infrastructure\GitHub\GitHubPublicationRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPublicationRepositoryTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testReadsPublishedReleaseAndMatchingPublisherRun(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/releases/101')) {
                return new MockResponse(json_encode([
                    'id' => 101,
                    'html_url' => 'https://example.test/releases/101',
                    'tag_name' => 'v15.0.4',
                    'target_commitish' => self::SHA,
                    'draft' => false,
                    'prerelease' => false,
                    'published_at' => '2026-09-21T18:00:00Z',
                    'assets' => [[
                        'id' => 303,
                        'name' => 'libresign-v15.0.4.tar.gz',
                        'url' => 'https://api.example.test/assets/303',
                        'digest' => 'sha256:' . str_repeat('b', 64),
                    ]],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            if (str_contains($url, '/actions/workflows/')) {
                return new MockResponse(json_encode([
                    'workflow_runs' => [
                        [
                            'id' => 201,
                            'html_url' => 'https://example.test/actions/runs/201',
                            'head_sha' => self::SHA,
                            'event' => 'release',
                            'status' => 'completed',
                            'conclusion' => 'success',
                            'created_at' => '2026-09-21T17:59:00Z',
                        ],
                        [
                            'id' => 202,
                            'html_url' => 'https://example.test/actions/runs/202',
                            'head_sha' => self::SHA,
                            'event' => 'release',
                            'status' => 'completed',
                            'conclusion' => 'success',
                            'created_at' => '2026-09-21T18:01:00Z',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse('not found', ['http_code' => 404]);
        });

        $repository = new GitHubPublicationRepository(client: $client, apiUrl: 'https://api.example.test');
        $release = $repository->release('LibreSign/libresign', 101);
        self::assertNotNull($release);
        self::assertFalse($release->draft);
        self::assertSame(str_repeat('b', 64), $release->assets[0]->sha256);

        $run = $repository->publisherRun(
            'LibreSign/libresign',
            'appstore-build-publish.yml',
            self::SHA,
            '2026-09-21T18:00:00Z',
        );
        self::assertNotNull($run);
        self::assertSame(202, $run->id);
        self::assertSame('success', $run->conclusion);
    }

    public function testDownloadsExactReleaseAsset(): void
    {
        $client = new MockHttpClient(new MockResponse('archive-bytes', ['http_code' => 200]));
        $repository = new GitHubPublicationRepository(client: $client, apiUrl: 'https://api.example.test');
        $target = tempnam(sys_get_temp_dir(), 'release-asset-');
        self::assertIsString($target);

        try {
            $repository->downloadAsset('LibreSign/libresign', 303, $target);
            self::assertSame('archive-bytes', file_get_contents($target));
        } finally {
            @unlink($target);
        }
    }
}
