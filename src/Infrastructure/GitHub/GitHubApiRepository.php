<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitHubRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GitHubApiRepository implements GitHubRepository
{
    private HttpClientInterface $client;

    public function __construct(?string $token = null, ?HttpClientInterface $client = null)
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
            'base_uri' => 'https://api.github.com',
            'headers' => $headers,
        ]);
    }

    public static function fromEnvironment(): self
    {
        $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: null;

        return new self($token);
    }

    public function closedPullRequests(string $repository, string $baseBranch): array
    {
        return $this->pullRequests($repository, $baseBranch, 'closed');
    }

    public function openPullRequests(string $repository, string $baseBranch): array
    {
        return $this->pullRequests($repository, $baseBranch, 'open');
    }

    public function openMilestones(string $repository): array
    {
        $items = $this->paginate('/repos/' . $repository . '/milestones', [
            'state' => 'open',
            'sort' => 'due_on',
            'direction' => 'asc',
        ]);

        return array_map(
            static fn (array $item): MilestoneInfo => new MilestoneInfo(
                (int) $item['number'],
                (string) $item['title'],
                (string) $item['html_url'],
            ),
            $items,
        );
    }

    public function releaseExists(string $repository, string $tag): bool
    {
        $response = $this->client->request(
            'GET',
            '/repos/' . $repository . '/releases/tags/' . rawurlencode($tag),
        );
        $status = $response->getStatusCode();

        if ($status === 200) {
            return true;
        }
        if ($status === 404) {
            return false;
        }

        throw new DomainException(sprintf(
            'GitHub release lookup failed for %s (%d).',
            $tag,
            $status,
        ));
    }

    /**
     * @return list<PullRequestInfo>
     */
    private function pullRequests(string $repository, string $baseBranch, string $state): array
    {
        $items = $this->paginate('/repos/' . $repository . '/pulls', [
            'state' => $state,
            'base' => $baseBranch,
            'sort' => 'updated',
            'direction' => 'desc',
        ]);

        return array_map(
            static fn (array $item): PullRequestInfo => new PullRequestInfo(
                (int) $item['number'],
                (string) $item['title'],
                (string) ($item['body'] ?? ''),
                (string) $item['base']['ref'],
                isset($item['merge_commit_sha']) && is_string($item['merge_commit_sha'])
                    ? $item['merge_commit_sha']
                    : null,
                isset($item['merged_at']) && is_string($item['merged_at'])
                    ? $item['merged_at']
                    : null,
                (string) $item['html_url'],
                array_values(array_map(
                    static fn (array $label): string => (string) $label['name'],
                    is_array($item['labels'] ?? null) ? $item['labels'] : [],
                )),
                (string) ($item['user']['login'] ?? ''),
            ),
            $items,
        );
    }

    /**
     * @param array<string, scalar> $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $query): array
    {
        $page = 1;
        $items = [];

        do {
            $response = $this->client->request('GET', $path, [
                'query' => $query + ['per_page' => 100, 'page' => $page],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new DomainException(sprintf('GitHub API request failed: %s (%d).', $path, $status));
            }

            $batch = $response->toArray(false);
            if (!is_array($batch)) {
                throw new DomainException(sprintf('Unexpected GitHub API response: %s.', $path));
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            ++$page;
        } while (count($batch) === 100);

        return $items;
    }
}
