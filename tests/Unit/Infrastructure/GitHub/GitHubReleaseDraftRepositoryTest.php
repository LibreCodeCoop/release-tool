<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Unit\Infrastructure\GitHub;

use LibreCode\ReleaseTool\Infrastructure\GitHub\GitHubReleaseDraftRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubReleaseDraftRepositoryTest extends TestCase
{
    public function testDereferencesAnnotatedTagToCommit(): void
    {
        $client = new MockHttpClient([
            $this->json(['object' => ['type' => 'tag', 'sha' => str_repeat('b', 40)]]),
            $this->json(['object' => ['type' => 'commit', 'sha' => str_repeat('a', 40)]]),
        ], 'https://api.github.test');
        $repo = new GitHubReleaseDraftRepository('token', $client, 'https://api.github.test');
        self::assertSame(str_repeat('a', 40), $repo->tagTarget('LibreSign/libresign', 'v15.0.4'));
    }

    public function testMissingTagReturnsNull(): void
    {
        $client = new MockHttpClient([$this->json(['message' => 'Not Found'], 404)], 'https://api.github.test');
        $repo = new GitHubReleaseDraftRepository('token', $client, 'https://api.github.test');
        self::assertNull($repo->tagTarget('LibreSign/libresign', 'v15.0.4'));
    }

    public function testCreateDraftNeverPublishesOrGeneratesNotes(): void
    {
        $captured = null;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
                return $this->json([
                    'id' => 123,
                    'html_url' => 'https://example.test/releases/123',
                    'tag_name' => 'v15.0.4',
                    'target_commitish' => str_repeat('a', 40),
                    'body' => 'Release body',
                    'draft' => true,
                    'prerelease' => false,
                ], 201);
            },
            'https://api.github.test',
        );
        $repo = new GitHubReleaseDraftRepository('token', $client, 'https://api.github.test');
        $draft = $repo->createDraft(
            'LibreSign/libresign',
            'v15.0.4',
            str_repeat('a', 40),
            '15.0.4',
            'Release body',
            false,
        );
        self::assertTrue($draft->draft);
        self::assertIsArray($captured);
        self::assertTrue($captured['draft']);
        self::assertFalse($captured['generate_release_notes']);
        self::assertSame(str_repeat('a', 40), $captured['target_commitish']);
    }

    public function testReadsMergerAndPermission(): void
    {
        $client = new MockHttpClient([
            $this->json(['merged_at' => '2026-09-21T18:00:00Z', 'merged_by' => ['login' => 'alice']]),
            $this->json(['permission' => 'maintain']),
        ], 'https://api.github.test');
        $repo = new GitHubReleaseDraftRepository('token', $client, 'https://api.github.test');
        self::assertSame('alice', $repo->pullRequestMerger('LibreSign/libresign', 77));
        self::assertSame('maintain', $repo->permission('LibreSign/libresign', 'alice'));
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
