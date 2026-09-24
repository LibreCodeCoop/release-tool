<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\ReleasePreflightRepository;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubReleasePreflightRepository implements ReleasePreflightRepository
{
    private HttpClientInterface $client;

    public function __construct(?string $token = null, ?HttpClientInterface $client = null, string $apiUrl = 'https://api.github.com')
    {
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

    public function milestone(string $repository, string $title): ?array
    {
        $response = $this->client->request('GET', '/repos/' . $repository . '/milestones', [
            'query' => ['state' => 'all', 'per_page' => 100],
        ]);
        $this->assertSuccessful($response->getStatusCode(), 'milestone lookup');
        foreach ($response->toArray(false) as $item) {
            if (is_array($item) && ($item['title'] ?? null) === $title) {
                return [
                    'state' => (string) ($item['state'] ?? ''),
                    'open_issues' => (int) ($item['open_issues'] ?? 0),
                ];
            }
        }
        return null;
    }

    public function openItemCount(string $repository, string $query): int
    {
        $response = $this->client->request('GET', '/search/issues', [
            'query' => ['q' => trim(sprintf('repo:%s is:open %s', $repository, $query))],
        ]);
        $this->assertSuccessful($response->getStatusCode(), 'blocker search');
        $payload = $response->toArray(false);
        return (int) ($payload['total_count'] ?? 0);
    }

    private function assertSuccessful(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('GitHub %s failed (%d).', $operation, $status));
        }
    }
}
