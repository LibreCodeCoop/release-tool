<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Release;

use LibreCode\ReleaseTool\Application\Release\Port\ReleaseDraftRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseDraftInfo;

final class InMemoryReleaseDraftRepository implements ReleaseDraftRepository
{
    /** @param array<string, string> $branchHeads @param array<string, string> $permissions */
    public function __construct(
        private readonly array $branchHeads,
        private readonly string $merger = 'maintainer',
        private readonly array $permissions = ['maintainer' => 'maintain'],
        private readonly ?string $tagTarget = null,
        public ?ReleaseDraftInfo $release = null,
    ) {
    }

    public int $createCalls = 0;
    public int $updateCalls = 0;

    public function branchHead(string $repository, string $branch): string
    {
        return $this->branchHeads[$branch];
    }

    public function tagTarget(string $repository, string $tag): ?string
    {
        return $this->tagTarget;
    }

    public function releaseByTag(string $repository, string $tag): ?ReleaseDraftInfo
    {
        return $this->release;
    }

    public function pullRequestMerger(string $repository, int $pullRequestNumber): string
    {
        return $this->merger;
    }

    public function permission(string $repository, string $login): string
    {
        return $this->permissions[$login] ?? 'read';
    }

    public function createDraft(
        string $repository,
        string $tag,
        string $targetSha,
        string $name,
        string $body,
        bool $prerelease,
    ): ReleaseDraftInfo {
        ++$this->createCalls;
        return $this->release = new ReleaseDraftInfo(
            101,
            'https://example.test/releases/101',
            $tag,
            $targetSha,
            $body,
            true,
            $prerelease,
        );
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
        ++$this->updateCalls;
        return $this->release = new ReleaseDraftInfo(
            $releaseId,
            'https://example.test/releases/' . $releaseId,
            $tag,
            $targetSha,
            $body,
            true,
            $prerelease,
        );
    }
}
