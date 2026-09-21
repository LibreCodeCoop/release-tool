<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\MilestoneRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneWorkItem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubMilestoneRepository implements MilestoneRepository
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

    public function openMilestones(string $repository): array
    {
        return $this->milestones($repository, 'open');
    }

    public function closedMilestones(string $repository): array
    {
        return $this->milestones($repository, 'closed');
    }

    public function openItems(string $repository, int $milestoneNumber): array
    {
        $items = $this->paginate('/repos/' . $repository . '/issues', [
            'state' => 'open',
            'milestone' => $milestoneNumber,
            'sort' => 'created',
            'direction' => 'asc',
        ]);

        $result = [];
        foreach ($items as $item) {
            $number = $item['number'] ?? null;
            $url = $item['html_url'] ?? null;
            if (!is_int($number) || !is_string($url) || $url === '') {
                throw new DomainException('GitHub returned an invalid milestone work item.');
            }
            $result[] = new MilestoneWorkItem(
                $number,
                isset($item['pull_request']),
                $url,
            );
        }
        return $result;
    }

    public function renameMilestone(string $repository, int $milestoneNumber, string $title): MilestoneInfo
    {
        return $this->writeMilestone($repository, $milestoneNumber, ['title' => $title]);
    }

    public function createMilestone(string $repository, string $title): MilestoneInfo
    {
        $data = $this->request(
            'POST',
            '/repos/' . $repository . '/milestones',
            ['title' => $title],
        );
        return $this->milestoneInfo($data);
    }

    public function moveItem(string $repository, int $itemNumber, int $milestoneNumber): void
    {
        $this->request(
            'PATCH',
            sprintf('/repos/%s/issues/%d', $repository, $itemNumber),
            ['milestone' => $milestoneNumber],
        );
    }

    public function closeMilestone(string $repository, int $milestoneNumber): MilestoneInfo
    {
        return $this->writeMilestone($repository, $milestoneNumber, ['state' => 'closed']);
    }

    /** @return list<MilestoneInfo> */
    private function milestones(string $repository, string $state): array
    {
        return array_map(
            $this->milestoneInfo(...),
            $this->paginate('/repos/' . $repository . '/milestones', [
                'state' => $state,
                'sort' => 'due_on',
                'direction' => 'asc',
            ]),
        );
    }

    /** @param array<string, mixed> $patch */
    private function writeMilestone(
        string $repository,
        int $milestoneNumber,
        array $patch,
    ): MilestoneInfo {
        return $this->milestoneInfo($this->request(
            'PATCH',
            sprintf('/repos/%s/milestones/%d', $repository, $milestoneNumber),
            $patch,
        ));
    }

    /** @param array<string, mixed> $data */
    private function milestoneInfo(array $data): MilestoneInfo
    {
        $number = $data['number'] ?? null;
        $title = $data['title'] ?? null;
        $url = $data['html_url'] ?? null;
        if (!is_int($number) || !is_string($title) || !is_string($url) || $url === '') {
            throw new DomainException('GitHub returned invalid milestone metadata.');
        }
        return new MilestoneInfo($number, $title, $url);
    }

    /**
     * @param array<string, scalar> $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $query): array
    {
        $items = [];
        for ($page = 1; ; ++$page) {
            $response = $this->client->request('GET', $path, [
                'query' => $query + ['per_page' => 100, 'page' => $page],
            ]);
            $this->assertSuccess($response->getStatusCode(), 'GET ' . $path);
            $batch = $response->toArray(false);
            foreach ($batch as $item) {
                if (!is_array($item)) {
                    throw new DomainException('GitHub pagination returned a non-object item.');
                }
                $items[] = $item;
            }
            if (count($batch) < 100) {
                break;
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $response = $this->client->request(
            $method,
            $path,
            $json === null ? [] : ['json' => $json],
        );
        $this->assertSuccess($response->getStatusCode(), $method . ' ' . $path);
        $data = $response->toArray(false);
        if (array_is_list($data)) {
            throw new DomainException('GitHub returned an invalid object response.');
        }
        return $data;
    }

    private function assertSuccess(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('GitHub API failed to %s (%d).', $operation, $status));
        }
    }
}
