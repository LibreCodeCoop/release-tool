<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHub;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\ReleasePreparationPublishing;
use LibreCode\ReleaseTool\Domain\Release\FileChange;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubReleasePreparationPublisher implements ReleasePreparationPublishing
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

    public function publish(ReleasePreparation $preparation): ReleasePreparation
    {
        $targetHead = $this->refSha($preparation->repository, $preparation->targetBranch);
        if ($targetHead !== $preparation->planningBaseSha) {
            throw new DomainException(sprintf(
                'ReleasePreparation is stale: %s now points to %s, expected %s.',
                $preparation->targetBranch,
                $targetHead,
                $preparation->planningBaseSha,
            ));
        }

        $tree = $this->createTree($preparation);
        $generatedHead = $this->optionalRefSha($preparation->repository, $preparation->generatedBranch);

        if ($generatedHead !== null) {
            $commit = $this->request(
                'GET',
                sprintf('/repos/%s/git/commits/%s', $preparation->repository, $generatedHead),
            );
            $existingTree = $commit['tree']['sha'] ?? null;
            if (!is_string($existingTree) || $existingTree !== $tree) {
                throw new DomainException(
                    'Existing generated release branch differs from the deterministic preparation; manual edits will not be overwritten.',
                );
            }
        } else {
            $commit = $this->request(
                'POST',
                sprintf('/repos/%s/git/commits', $preparation->repository),
                [
                    'message' => sprintf('chore: prepare release %s', $preparation->version),
                    'tree' => $tree,
                    'parents' => [$preparation->planningBaseSha],
                ],
            );
            $generatedHead = $commit['sha'] ?? null;
            if (!is_string($generatedHead) || $generatedHead === '') {
                throw new DomainException('GitHub did not return the generated release commit SHA.');
            }

            $this->request(
                'POST',
                sprintf('/repos/%s/git/refs', $preparation->repository),
                [
                    'ref' => 'refs/heads/' . $preparation->generatedBranch,
                    'sha' => $generatedHead,
                ],
            );
        }

        $pullRequest = $this->findPullRequest($preparation);
        if ($pullRequest === null) {
            $pullRequest = $this->request(
                'POST',
                sprintf('/repos/%s/pulls', $preparation->repository),
                [
                    'title' => sprintf('chore: prepare release %s', $preparation->version),
                    'head' => $preparation->generatedBranch,
                    'base' => $preparation->targetBranch,
                    'body' => $this->pullRequestBody($preparation),
                    'maintainer_can_modify' => true,
                ],
            );
        } else {
            $body = $pullRequest['body'] ?? '';
            if (!is_string($body) || !str_contains($body, $preparation->prMarker)) {
                throw new DomainException(
                    'Existing pull request for generated branch does not carry the expected preparation marker.',
                );
            }
        }

        $number = $pullRequest['number'] ?? null;
        $url = $pullRequest['html_url'] ?? null;
        if (!is_int($number) || !is_string($url) || $url === '') {
            throw new DomainException('GitHub did not return a valid release preparation pull request.');
        }

        return $preparation->withPullRequest($number, $url);
    }
    private function createTree(ReleasePreparation $preparation): string
    {
        $entries = array_map(
            static function (FileChange $change): array {
                if ($change->content === null) {
                    throw new DomainException(sprintf(
                        'ReleasePreparation file content is unavailable for apply: %s',
                        $change->path,
                    ));
                }

                return [
                    'path' => $change->path,
                    'mode' => '100644',
                    'type' => 'blob',
                    'content' => $change->content,
                ];
            },
            $preparation->fileChanges,
        );

        $baseCommit = $this->request(
            'GET',
            sprintf('/repos/%s/git/commits/%s', $preparation->repository, $preparation->planningBaseSha),
        );
        $baseTree = $baseCommit['tree']['sha'] ?? null;
        if (!is_string($baseTree) || $baseTree === '') {
            throw new DomainException('GitHub did not return the planning base tree SHA.');
        }

        $tree = $this->request(
            'POST',
            sprintf('/repos/%s/git/trees', $preparation->repository),
            [
                'base_tree' => $baseTree,
                'tree' => $entries,
            ],
        );
        $sha = $tree['sha'] ?? null;
        if (!is_string($sha) || $sha === '') {
            throw new DomainException('GitHub did not return the generated release tree SHA.');
        }

        return $sha;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPullRequest(ReleasePreparation $preparation): ?array
    {
        [$owner] = explode('/', $preparation->repository, 2);
        $response = $this->client->request(
            'GET',
            sprintf('/repos/%s/pulls', $preparation->repository),
            [
                'query' => [
                    'state' => 'open',
                    'base' => $preparation->targetBranch,
                    'head' => $owner . ':' . $preparation->generatedBranch,
                    'per_page' => 10,
                ],
            ],
        );
        $this->assertSuccess($response->getStatusCode(), 'list release preparation pull requests');
        $items = $response->toArray(false);

        foreach ($items as $item) {
            if (is_array($item)) {
                return $item;
            }
        }

        return null;
    }
    private function refSha(string $repository, string $branch): string
    {
        $sha = $this->optionalRefSha($repository, $branch);
        if ($sha === null) {
            throw new DomainException(sprintf('GitHub branch does not exist: %s', $branch));
        }

        return $sha;
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

    private function pullRequestBody(ReleasePreparation $preparation): string
    {
        return implode("\n", [
            $preparation->prMarker,
            '',
            sprintf('ReleasePlan: `%s`', $preparation->releasePlanId),
            sprintf('ReleasePreparation: `%s`', $preparation->id),
            sprintf('Version: `%s`', $preparation->version),
            sprintf('Channel: `%s`', $preparation->channel->value),
            sprintf('Mode: `%s`', $preparation->mode->value),
            sprintf('Planning base: `%s`', $preparation->planningBaseSha),
            sprintf('Changelog SHA-256: `%s`', $preparation->changelogSha256),
            '',
            'This pull request was generated from a deterministic ReleasePreparation v1.',
            'Review the exact file diff before merging.',
        ]);
    }
}
