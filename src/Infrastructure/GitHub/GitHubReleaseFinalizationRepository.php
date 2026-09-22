<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\HistorySyncRequest;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseFinalizationRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\FinalizedPullRequest;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubReleaseFinalizationRepository implements ReleaseFinalizationRepository
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

    public function pullRequest(string $repository, int $number): FinalizedPullRequest
    {
        $pullRequest = $this->request(
            'GET',
            sprintf('/repos/%s/pulls/%d', $repository, $number),
        );
        $files = $this->paginate(
            sprintf('/repos/%s/pulls/%d/files', $repository, $number),
        );

        $changedFiles = [];
        foreach ($files as $file) {
            $filename = $file['filename'] ?? null;
            if (!is_string($filename) || $filename === '') {
                throw new DomainException('GitHub returned an invalid pull request filename.');
            }
            $changedFiles[] = $filename;
        }
        sort($changedFiles);

        $mergedAt = $pullRequest['merged_at'] ?? null;
        $mergeCommitSha = $pullRequest['merge_commit_sha'] ?? null;
        $baseBranch = $pullRequest['base']['ref'] ?? null;
        $url = $pullRequest['html_url'] ?? null;
        if (!is_string($baseBranch) || !is_string($url)) {
            throw new DomainException('GitHub returned invalid release pull request metadata.');
        }

        return new FinalizedPullRequest(
            $number,
            $url,
            $baseBranch,
            is_string($mergedAt) && $mergedAt !== '',
            is_string($mergeCommitSha) && $mergeCommitSha !== '' ? $mergeCommitSha : null,
            $changedFiles,
        );
    }

    public function branchHead(string $repository, string $branch): string
    {
        $data = $this->request(
            'GET',
            sprintf('/repos/%s/git/ref/heads/%s', $repository, $this->encodeRef($branch)),
        );
        $sha = $data['object']['sha'] ?? null;
        if (!is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new DomainException(sprintf('GitHub returned an invalid branch head for %s.', $branch));
        }
        return $sha;
    }

    /** @return list<string> */
    public function branches(string $repository): array
    {
        $items = $this->paginate(sprintf('/repos/%s/branches', $repository));
        $branches = [];
        foreach ($items as $item) {
            $name = $item['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new DomainException('GitHub returned an invalid branch name.');
            }
            $branches[] = $name;
        }

        return $branches;
    }

    public function publishHistorySynchronization(HistorySyncRequest $request): HistorySynchronization
    {
        $currentHead = $this->branchHead($request->repository, $request->targetBranch);
        if ($currentHead !== $request->expectedBaseSha) {
            throw new DomainException(sprintf(
                'History synchronization is stale: %s now points to %s, expected %s.',
                $request->targetBranch,
                $currentHead,
                $request->expectedBaseSha,
            ));
        }

        $baseCommit = $this->request(
            'GET',
            sprintf('/repos/%s/git/commits/%s', $request->repository, $request->expectedBaseSha),
        );
        $baseTree = $baseCommit['tree']['sha'] ?? null;
        if (!is_string($baseTree) || $baseTree === '') {
            throw new DomainException('GitHub did not return the history base tree SHA.');
        }

        $tree = $this->request(
            'POST',
            sprintf('/repos/%s/git/trees', $request->repository),
            [
                'base_tree' => $baseTree,
                'tree' => [[
                    'path' => $request->targetPath,
                    'mode' => '100644',
                    'type' => 'blob',
                    'content' => $request->content,
                ]],
            ],
        );
        $treeSha = $tree['sha'] ?? null;
        if (!is_string($treeSha) || $treeSha === '') {
            throw new DomainException('GitHub did not return the history synchronization tree SHA.');
        }

        $generatedHead = $this->optionalRefSha($request->repository, $request->generatedBranch);
        if ($generatedHead !== null) {
            $commit = $this->request(
                'GET',
                sprintf('/repos/%s/git/commits/%s', $request->repository, $generatedHead),
            );
            $existingTree = $commit['tree']['sha'] ?? null;
            if (!is_string($existingTree) || $existingTree !== $treeSha) {
                throw new DomainException('Existing history synchronization branch was edited and will not be overwritten.');
            }
        } else {
            $commit = $this->request(
                'POST',
                sprintf('/repos/%s/git/commits', $request->repository),
                [
                    'message' => sprintf('docs: synchronize release %s history [skip ci]', $request->version),
                    'tree' => $treeSha,
                    'parents' => [$request->expectedBaseSha],
                ],
            );
            $generatedHead = $commit['sha'] ?? null;
            if (!is_string($generatedHead) || $generatedHead === '') {
                throw new DomainException('GitHub did not return the history synchronization commit SHA.');
            }
            $this->request(
                'POST',
                sprintf('/repos/%s/git/refs', $request->repository),
                [
                    'ref' => 'refs/heads/' . $request->generatedBranch,
                    'sha' => $generatedHead,
                ],
            );
        }

        $pullRequest = $this->findHistoryPullRequest($request);
        if ($pullRequest === null) {
            $pullRequest = $this->request(
                'POST',
                sprintf('/repos/%s/pulls', $request->repository),
                [
                    'title' => sprintf('docs: synchronize release %s history', $request->version),
                    'head' => $request->generatedBranch,
                    'base' => $request->targetBranch,
                    'body' => $request->marker . "\n\nThis PR synchronizes the exact released changelog section only.",
                    'maintainer_can_modify' => true,
                ],
            );
        } else {
            $body = $pullRequest['body'] ?? '';
            if (!is_string($body) || !str_contains($body, $request->marker)) {
                throw new DomainException('Existing history synchronization PR is missing the expected marker.');
            }
        }

        $number = $pullRequest['number'] ?? null;
        $url = $pullRequest['html_url'] ?? null;
        if (!is_int($number) || !is_string($url) || $url === '') {
            throw new DomainException('GitHub returned an invalid history synchronization pull request.');
        }

        return new HistorySynchronization(
            HistorySyncState::PullRequestOpen,
            $request->targetBranch,
            $request->targetPath,
            $request->generatedBranch,
            $number,
            $url,
        );
    }
    /** @return array<string, mixed>|null */
    private function findHistoryPullRequest(HistorySyncRequest $request): ?array
    {
        [$owner] = explode('/', $request->repository, 2);
        $response = $this->client->request(
            'GET',
            sprintf('/repos/%s/pulls', $request->repository),
            [
                'query' => [
                    'state' => 'open',
                    'base' => $request->targetBranch,
                    'head' => $owner . ':' . $request->generatedBranch,
                    'per_page' => 10,
                ],
            ],
        );
        $this->assertSuccess($response->getStatusCode(), 'list history synchronization pull requests');
        foreach ($response->toArray(false) as $item) {
            if (is_array($item)) {
                return $item;
            }
        }
        return null;
    }

    private function optionalRefSha(string $repository, string $branch): ?string
    {
        $response = $this->client->request(
            'GET',
            sprintf('/repos/%s/git/ref/heads/%s', $repository, $this->encodeRef($branch)),
        );
        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        $this->assertSuccess($status, 'read GitHub branch ref');
        $data = $response->toArray(false);
        $sha = $data['object']['sha'] ?? null;
        if (!is_string($sha) || $sha === '') {
            throw new DomainException(sprintf('GitHub returned an invalid ref for %s.', $branch));
        }
        return $sha;
    }

    /** @return list<array<string, mixed>> */
    private function paginate(string $path): array
    {
        $items = [];
        for ($page = 1; ; ++$page) {
            $response = $this->client->request('GET', $path, [
                'query' => ['per_page' => 100, 'page' => $page],
            ]);
            $this->assertSuccess($response->getStatusCode(), 'paginate GitHub API');
            $batch = $response->toArray(false);
            if ($batch === []) {
                break;
            }
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
        return $response->toArray(false);
    }

    private function assertSuccess(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('GitHub API failed to %s (%d).', $operation, $status));
        }
    }

    private function encodeRef(string $branch): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $branch)));
    }
}
