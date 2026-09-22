<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseDraftRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseDraftInfo;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubReleaseDraftRepository implements ReleaseDraftRepository
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

    public function branchHead(string $repository, string $branch): string
    {
        $data = $this->request('GET', sprintf(
            '/repos/%s/git/ref/heads/%s',
            $repository,
            $this->encodeRef($branch),
        ));
        $sha = $data['object']['sha'] ?? null;
        if (!is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new DomainException(sprintf('GitHub returned an invalid branch head for %s.', $branch));
        }
        return $sha;
    }

    public function tagTarget(string $repository, string $tag): ?string
    {
        $response = $this->client->request('GET', sprintf(
            '/repos/%s/git/ref/tags/%s',
            $repository,
            $this->encodeRef($tag),
        ));
        if ($response->getStatusCode() === 404) {
            return null;
        }
        $this->assertSuccess($response->getStatusCode(), 'read release tag');
        $data = $response->toArray(false);
        $type = $data['object']['type'] ?? null;
        $sha = $data['object']['sha'] ?? null;
        if (!is_string($type) || !is_string($sha) || $sha === '') {
            throw new DomainException('GitHub returned invalid tag metadata.');
        }

        for ($depth = 0; $depth < 5; ++$depth) {
            if ($type === 'commit') {
                return $sha;
            }
            if ($type !== 'tag') {
                throw new DomainException(sprintf('Unsupported Git tag object type: %s', $type));
            }
            $tagObject = $this->request(
                'GET',
                sprintf('/repos/%s/git/tags/%s', $repository, $sha),
            );
            $type = $tagObject['object']['type'] ?? null;
            $sha = $tagObject['object']['sha'] ?? null;
            if (!is_string($type) || !is_string($sha) || $sha === '') {
                throw new DomainException('GitHub returned invalid annotated tag metadata.');
            }
        }

        throw new DomainException('Annotated tag chain is too deep.');
    }

    public function releaseByTag(string $repository, string $tag): ?ReleaseDraftInfo
    {
        foreach ($this->paginate('/repos/' . $repository . '/releases') as $item) {
            if (($item['tag_name'] ?? null) === $tag) {
                return $this->releaseInfo($item);
            }
        }
        return null;
    }

    public function pullRequestMerger(string $repository, int $pullRequestNumber): string
    {
        $data = $this->request(
            'GET',
            sprintf('/repos/%s/pulls/%d', $repository, $pullRequestNumber),
        );
        $mergedAt = $data['merged_at'] ?? null;
        $login = $data['merged_by']['login'] ?? null;
        if (!is_string($mergedAt) || $mergedAt === '' || !is_string($login) || $login === '') {
            throw new DomainException('Release preparation pull request is not merged by an identifiable maintainer.');
        }
        return $login;
    }

    public function pullRequestAuthor(string $repository, int $pullRequestNumber): string
    {
        $data = $this->request(
            'GET',
            sprintf('/repos/%s/pulls/%d', $repository, $pullRequestNumber),
        );
        $login = $data['user']['login'] ?? null;
        if (!is_string($login) || $login === '') {
            throw new DomainException('GitHub returned no identifiable pull request author.');
        }

        return $login;
    }

    public function permission(string $repository, string $login): string
    {
        $data = $this->request(
            'GET',
            sprintf('/repos/%s/collaborators/%s/permission', $repository, rawurlencode($login)),
        );
        $permission = $data['permission'] ?? null;
        if (!is_string($permission) || $permission === '') {
            throw new DomainException(sprintf('GitHub returned no repository permission for %s.', $login));
        }
        return $permission;
    }

    public function createDraft(
        string $repository,
        string $tag,
        string $targetSha,
        string $name,
        string $body,
        bool $prerelease,
    ): ReleaseDraftInfo {
        return $this->releaseInfo($this->request(
            'POST',
            '/repos/' . $repository . '/releases',
            $this->payload($tag, $targetSha, $name, $body, $prerelease),
        ));
    }

    public function updateDraft(
        string $repository,
        int $releaseId,
        string $tag,
        string $targetSha,
        string $name,
        string $body,
        bool $prerelease,
    ): ReleaseDraftInfo {
        return $this->releaseInfo($this->request(
            'PATCH',
            sprintf('/repos/%s/releases/%d', $repository, $releaseId),
            $this->payload($tag, $targetSha, $name, $body, $prerelease),
        ));
    }

    /** @return array<string, mixed> */
    private function payload(
        string $tag,
        string $targetSha,
        string $name,
        string $body,
        bool $prerelease,
    ): array {
        return [
            'tag_name' => $tag,
            'target_commitish' => $targetSha,
            'name' => $name,
            'body' => $body,
            'draft' => true,
            'prerelease' => $prerelease,
            'generate_release_notes' => false,
        ];
    }

    /** @param array<string, mixed> $data */
    private function releaseInfo(array $data): ReleaseDraftInfo
    {
        $id = $data['id'] ?? null;
        $url = $data['html_url'] ?? null;
        $tag = $data['tag_name'] ?? null;
        $target = $data['target_commitish'] ?? null;
        $body = $data['body'] ?? '';
        $draft = $data['draft'] ?? null;
        $prerelease = $data['prerelease'] ?? null;
        if (
            !is_int($id)
            || !is_string($url)
            || !is_string($tag)
            || !is_string($target)
            || !is_string($body)
            || !is_bool($draft)
            || !is_bool($prerelease)
        ) {
            throw new DomainException('GitHub returned invalid release draft metadata.');
        }
        return new ReleaseDraftInfo($id, $url, $tag, $target, $body, $draft, $prerelease);
    }

    /** @return list<array<string, mixed>> */
    private function paginate(string $path): array
    {
        $items = [];
        for ($page = 1; ; ++$page) {
            $response = $this->client->request('GET', $path, [
                'query' => ['per_page' => 100, 'page' => $page],
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
            throw new DomainException('GitHub returned an unexpected list response.');
        }
        return $data;
    }

    private function assertSuccess(int $status, string $operation): void
    {
        if ($status < 200 || $status >= 300) {
            throw new DomainException(sprintf('GitHub API failed to %s (%d).', $operation, $status));
        }
    }

    private function encodeRef(string $ref): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $ref)));
    }
}
