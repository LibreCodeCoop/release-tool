<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Publication\Port\PublicationRepository;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedAsset;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublishedRelease;
use LibreCode\ReleaseTool\Application\Publication\ReadModel\PublisherRun;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubPublicationRepository implements PublicationRepository
{
    private HttpClientInterface $client;

    public function __construct(
        ?string $token = null,
        ?HttpClientInterface $client = null,
        string $apiUrl = 'https://api.github.com',
    ) {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'LibreCode-release-tool',
        ];
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->client = $client ?? HttpClient::create([
            'base_uri' => rtrim($apiUrl, '/'),
            'headers' => $headers,
        ]);
    }

    public static function fromEnvironment(): self
    {
        return new self(
            getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: null,
            apiUrl: getenv('GITHUB_API_URL') ?: 'https://api.github.com',
        );
    }

    public function release(string $repository, int $releaseId): ?PublishedRelease
    {
        $response = $this->client->request('GET', sprintf('/repos/%s/releases/%d', $repository, $releaseId));
        if ($response->getStatusCode() === 404) {
            return null;
        }
        $this->assertSuccess($response->getStatusCode(), 'read published release');
        $data = $response->toArray(false);

        $assets = [];
        foreach (($data['assets'] ?? []) as $item) {
            if (!is_array($item)) {
                throw new DomainException('GitHub returned invalid release asset metadata.');
            }
            $digest = $item['digest'] ?? null;
            if (is_string($digest) && str_starts_with($digest, 'sha256:')) {
                $digest = substr($digest, 7);
            } elseif ($digest !== null) {
                $digest = null;
            }
            $assets[] = new PublishedAsset(
                (int) ($item['id'] ?? 0),
                (string) ($item['name'] ?? ''),
                (string) ($item['url'] ?? ''),
                is_string($digest) && preg_match('/^[0-9a-f]{64}$/i', $digest) === 1 ? strtolower($digest) : null,
            );
        }

        return new PublishedRelease(
            (int) ($data['id'] ?? 0),
            (string) ($data['html_url'] ?? ''),
            (string) ($data['tag_name'] ?? ''),
            (string) ($data['target_commitish'] ?? ''),
            (bool) ($data['draft'] ?? true),
            (bool) ($data['prerelease'] ?? false),
            isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : null,
            $assets,
        );
    }

    public function publisherRun(
        string $repository,
        string $workflow,
        string $headSha,
        ?string $publishedAt,
    ): ?PublisherRun {
        $response = $this->client->request(
            'GET',
            sprintf('/repos/%s/actions/workflows/%s/runs', $repository, rawurlencode($workflow)),
            ['query' => ['event' => 'release', 'status' => 'completed', 'head_sha' => $headSha, 'per_page' => 100]],
        );
        $this->assertSuccess($response->getStatusCode(), 'read publisher workflow runs');
        $data = $response->toArray(false);
        $runs = $data['workflow_runs'] ?? null;
        if (!is_array($runs)) {
            throw new DomainException('GitHub returned invalid workflow run metadata.');
        }

        $matches = [];
        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }
            $createdAt = $run['created_at'] ?? null;
            if (
                ($run['event'] ?? null) !== 'release'
                || ($run['head_sha'] ?? null) !== $headSha
                || !is_string($createdAt)
                || ($publishedAt !== null && strcmp($createdAt, $publishedAt) < 0)
            ) {
                continue;
            }
            $matches[] = $run;
        }

        if ($matches === []) {
            return null;
        }
        usort(
            $matches,
            static fn (array $left, array $right): int =>
                strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? '')),
        );
        $run = $matches[0];

        return new PublisherRun(
            (int) ($run['id'] ?? 0),
            (string) ($run['html_url'] ?? ''),
            (string) $run['head_sha'],
            (string) $run['event'],
            (string) ($run['status'] ?? ''),
            isset($run['conclusion']) && is_string($run['conclusion']) ? $run['conclusion'] : null,
            (string) ($run['created_at'] ?? ''),
        );
    }

    public function downloadAsset(string $repository, int $assetId, string $targetPath): void
    {
        $response = $this->client->request(
            'GET',
            sprintf('/repos/%s/releases/assets/%d', $repository, $assetId),
            ['headers' => ['Accept' => 'application/octet-stream']],
        );
        $this->assertSuccess($response->getStatusCode(), 'download release asset');
        $bytes = $response->getContent(false);
        if (file_put_contents($targetPath, $bytes) === false) {
            throw new DomainException(sprintf('Could not write release asset to %s.', $targetPath));
        }
    }

    private function assertSuccess(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('GitHub API failed to %s (%d).', $operation, $status));
        }
    }
}
