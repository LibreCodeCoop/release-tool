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

    public function publishHistorySynchronization(HistorySyncRequest $request): HistorySynchronization
    {
        throw new DomainException('Finalization repository incomplete.');
    }
}
