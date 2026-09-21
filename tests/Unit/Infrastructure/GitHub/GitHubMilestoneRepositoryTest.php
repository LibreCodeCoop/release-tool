<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Infrastructure\GitHub;

use LibreCode\ReleaseTool\Infrastructure\GitHub\GitHubMilestoneRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubMilestoneRepositoryTest extends TestCase
{
    public function testReadsMilestonesAndOpenItems(): void
    {
        $client = new MockHttpClient([
            $this->json([[
                'number' => 7,
                'title' => 'Next Patch (35)',
                'html_url' => 'https://example.test/milestones/7',
            ]]),
            $this->json([
                [
                    'number' => 10,
                    'html_url' => 'https://example.test/issues/10',
                ],
                [
                    'number' => 11,
                    'html_url' => 'https://example.test/pull/11',
                    'pull_request' => ['url' => 'https://api.example.test/pulls/11'],
                ],
            ]),
        ], 'https://api.github.test');
        $repo = new GitHubMilestoneRepository('token', $client, 'https://api.github.test');

        $milestones = $repo->openMilestones('LibreSign/libresign');
        $items = $repo->openItems('LibreSign/libresign', 7);

        self::assertSame('Next Patch (35)', $milestones[0]->title);
        self::assertFalse($items[0]->pullRequest);
        self::assertTrue($items[1]->pullRequest);
    }

    public function testMutatesOnlyThroughMilestoneAndIssueEndpoints(): void
    {
        $requests = [];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, parse_url($url, PHP_URL_PATH), $options['json'] ?? null];
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($method === 'PATCH' && $path === '/repos/LibreSign/libresign/milestones/7') {
                    $body = $options['json'] ?? [];
                    return $this->json([
                        'number' => 7,
                        'title' => (string) ($body['title'] ?? '15.0.4'),
                        'html_url' => 'https://example.test/milestones/7',
                    ]);
                }
                if ($method === 'POST' && $path === '/repos/LibreSign/libresign/milestones') {
                    return $this->json([
                        'number' => 8,
                        'title' => 'Next Patch (35)',
                        'html_url' => 'https://example.test/milestones/8',
                    ], 201);
                }
                if ($method === 'PATCH' && $path === '/repos/LibreSign/libresign/issues/10') {
                    return $this->json(['number' => 10]);
                }
                return $this->json(['message' => 'unexpected'], 500);
            },
            'https://api.github.test',
        );
        $repo = new GitHubMilestoneRepository('token', $client, 'https://api.github.test');

        $repo->renameMilestone('LibreSign/libresign', 7, '15.0.4');
        $followUp = $repo->createMilestone('LibreSign/libresign', 'Next Patch (35)');
        $repo->moveItem('LibreSign/libresign', 10, $followUp->number);

        self::assertSame(
            [
                ['PATCH', '/repos/LibreSign/libresign/milestones/7', ['title' => '15.0.4']],
                ['POST', '/repos/LibreSign/libresign/milestones', ['title' => 'Next Patch (35)']],
                ['PATCH', '/repos/LibreSign/libresign/issues/10', ['milestone' => 8]],
            ],
            $requests,
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
