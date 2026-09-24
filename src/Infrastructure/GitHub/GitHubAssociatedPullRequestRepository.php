<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\ReleaseNotes\AssociatedPullRequest;
use LibreCode\ReleaseTool\Application\ReleaseNotes\Port\AssociatedPullRequestRepository;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubAssociatedPullRequestRepository implements AssociatedPullRequestRepository
{
    private HttpClientInterface $client;

    private bool $hasToken;

    public function __construct(
        string $token,
        ?HttpClientInterface $client = null,
        string $apiUrl = 'https://api.github.com',
    ) {
        $this->hasToken = trim($token) !== '';
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'LibreCode-release-tool',
            ],
        ];
        if ($this->hasToken) {
            $options['auth_bearer'] = $token;
        }

        $this->client = $client ?? HttpClient::createForBaseUri(rtrim($apiUrl, '/') . '/', $options);
    }

    public static function fromEnvironment(): self
    {
        $token = getenv('RELEASE_NOTES_GITHUB_TOKEN');
        $apiUrl = getenv('GITHUB_API_URL');

        return new self(
            is_string($token) ? $token : '',
            apiUrl: is_string($apiUrl) && $apiUrl !== '' ? $apiUrl : 'https://api.github.com',
        );
    }

    public function forCommit(string $repository, string $sha): array
    {
        if (!$this->hasToken) {
            throw new InvalidArgumentException('github token is required');
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new InvalidArgumentException('repository must be in owner/name form');
        }

        $response = $this->client->request(
            'GET',
            'repos/' . $repository . '/commits/' . rawurlencode($sha) . '/pulls',
        );
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf(
                'GitHub API request failed (%d): %s',
                $response->getStatusCode(),
                $response->getContent(false),
            ));
        }

        $payload = $response->toArray(false);
        if (!array_is_list($payload)) {
            throw new RuntimeException(sprintf('unexpected pull request response for commit %s', $sha));
        }

        $pullRequests = [];
        foreach ($payload as $item) {
            if (!is_array($item) || !is_int($item['number'] ?? null)) {
                continue;
            }
            $base = is_array($item['base'] ?? null) ? $item['base'] : [];
            $pullRequests[] = new AssociatedPullRequest(
                $item['number'],
                is_string($item['title'] ?? null) ? $item['title'] : '',
                is_string($item['merged_at'] ?? null) ? $item['merged_at'] : '',
                is_string($base['ref'] ?? null) ? $base['ref'] : null,
            );
        }

        return $pullRequests;
    }
}
