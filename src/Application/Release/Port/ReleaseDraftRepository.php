<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

use LibreCode\ReleaseTool\Application\Release\ReadModel\ReleaseDraftInfo;

interface ReleaseDraftRepository
{
    public function branchHead(string $repository, string $branch): string;

    public function tagTarget(string $repository, string $tag): ?string;

    public function releaseByTag(string $repository, string $tag): ?ReleaseDraftInfo;

    public function pullRequestMerger(string $repository, int $pullRequestNumber): string;

    public function permission(string $repository, string $login): string;

    public function createDraft(
        string $repository,
        string $tag,
        string $targetSha,
        string $name,
        string $body,
        bool $prerelease,
    ): ReleaseDraftInfo;

    public function updateDraft(
        string $repository,
        int $releaseId,
        string $tag,
        string $targetSha,
        string $name,
        string $body,
        bool $prerelease,
    ): ReleaseDraftInfo;
}
