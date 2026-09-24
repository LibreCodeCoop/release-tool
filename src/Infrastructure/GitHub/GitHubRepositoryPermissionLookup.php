<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Security\Port\RepositoryPermissionLookup;
use LibreCode\ReleaseTool\Domain\Security\RepositoryPermission;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubRepositoryPermissionLookup implements RepositoryPermissionLookup
{
    private HttpClientInterface $client;

    private bool $hasToken;

    public function __construct(
        ?string $token = null,
        ?HttpClientInterface $client = null,
        string $apiUrl = 'https://api.github.com',
    ) {
        $this->hasToken = $token !== null && trim($token) !== '';

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'LibreCode-release-tool',
        ];
        if ($this->hasToken) {
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

    public function permission(string $repository, string $actor): RepositoryPermission
    {
        if (!$this->hasToken) {
            throw new InvalidArgumentException('GitHub token must not be empty.');
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new InvalidArgumentException('Repository must use owner/name form.');
        }
        if (trim($actor) === '') {
            throw new InvalidArgumentException('Actor must not be empty.');
        }

        $response = $this->client->request(
            'GET',
            '/repos/' . $repository . '/collaborators/' . rawurlencode($actor) . '/permission',
        );
        $status = $response->getStatusCode();

        if ($status === 404) {
            return RepositoryPermission::None;
        }
        if ($status !== 200) {
            throw new DomainException(sprintf('GitHub permission lookup failed with HTTP %d.', $status));
        }

        $payload = $response->toArray(false);
        $permission = is_string($payload['permission'] ?? null) ? $payload['permission'] : '';

        return RepositoryPermission::tryFrom($permission)
            ?? throw new DomainException('GitHub returned an invalid repository permission.');
    }
}
