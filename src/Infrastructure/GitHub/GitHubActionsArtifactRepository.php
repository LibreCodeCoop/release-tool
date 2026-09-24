<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Artifact\ActionsArtifact;
use LibreCode\ReleaseTool\Application\Artifact\ActionsWorkflowRun;
use LibreCode\ReleaseTool\Application\Artifact\Port\ActionsArtifactRepository;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubActionsArtifactRepository implements ActionsArtifactRepository
{
    private HttpClientInterface $client;

    public function __construct(
        string $token,
        ?HttpClientInterface $client = null,
        string $apiUrl = 'https://api.github.com',
    ) {
        if (trim($token) === '') {
            throw new InvalidArgumentException('GitHub token must not be empty.');
        }

        $this->client = $client ?? HttpClient::createForBaseUri(
            rtrim($apiUrl, '/') . '/',
            [
                'auth_bearer' => $token,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'LibreCode-release-tool',
                ],
            ],
        );
    }

    public static function fromEnvironment(): self
    {
        return new self(
            getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: '',
            apiUrl: getenv('GITHUB_API_URL') ?: 'https://api.github.com',
        );
    }

    public function artifacts(string $repository, string $name): array
    {
        $this->assertRepository($repository);
        $response = $this->client->request(
            'GET',
            'repos/' . $repository . '/actions/artifacts',
            ['query' => ['name' => $name, 'per_page' => 100]],
        );
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf(
                'GitHub artifact listing failed with HTTP %d.',
                $response->getStatusCode(),
            ));
        }

        $payload = $response->toArray(false);
        if (!is_array($payload['artifacts'] ?? null)) {
            throw new RuntimeException('GitHub returned an invalid artifact listing');
        }

        $artifacts = [];
        foreach ($payload['artifacts'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $run = is_array($item['workflow_run'] ?? null) ? $item['workflow_run'] : [];
            $url = $item['archive_download_url'] ?? null;
            if (!is_string($url) || $url === '' || !is_numeric($item['id'] ?? null)) {
                continue;
            }

            $artifacts[] = new ActionsArtifact(
                (int) $item['id'],
                is_string($item['name'] ?? null) ? $item['name'] : '',
                ($item['expired'] ?? false) === true,
                is_string($item['created_at'] ?? null) ? $item['created_at'] : '',
                $url,
                is_numeric($run['id'] ?? null) ? (int) $run['id'] : null,
                is_string($run['head_sha'] ?? null) ? $run['head_sha'] : null,
            );
        }

        return $artifacts;
    }

    public function workflowRun(string $repository, int $runId): ActionsWorkflowRun
    {
        $this->assertRepository($repository);
        $response = $this->client->request(
            'GET',
            'repos/' . $repository . '/actions/runs/' . $runId,
        );
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf(
                'GitHub workflow run lookup failed with HTTP %d.',
                $response->getStatusCode(),
            ));
        }

        $payload = $response->toArray(false);
        $event = $payload['event'] ?? null;
        $path = $payload['path'] ?? null;
        if (!is_string($event) || !is_string($path)) {
            throw new RuntimeException('GitHub returned an invalid workflow run');
        }

        return new ActionsWorkflowRun($event, $path);
    }

    public function download(string $url, string $destination): void
    {
        $response = $this->client->request('GET', $url);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf(
                'GitHub artifact download failed with HTTP %d.',
                $response->getStatusCode(),
            ));
        }

        $content = $response->getContent();
        if (file_put_contents($destination, $content) === false) {
            throw new RuntimeException(sprintf('Could not write downloaded artifact: %s', $destination));
        }
    }

    private function assertRepository(string $repository): void
    {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new InvalidArgumentException('Repository must use owner/name form.');
        }
    }
}
