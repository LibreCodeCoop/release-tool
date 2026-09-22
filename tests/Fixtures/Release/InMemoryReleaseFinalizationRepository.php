<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Release;

use LibreCode\ReleaseTool\Application\Release\HistorySyncRequest;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseFinalizationRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\FinalizedPullRequest;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;

final class InMemoryReleaseFinalizationRepository implements ReleaseFinalizationRepository
{
    /** @param array<string, string> $branchHeads */
    public function __construct(
        private readonly FinalizedPullRequest $pullRequest,
        private readonly array $branchHeads,
        private readonly ?HistorySynchronization $publishedHistory = null,
    ) {
    }

    public ?HistorySyncRequest $lastHistoryRequest = null;

    /** @var list<HistorySyncRequest> */
    public array $historyRequests = [];

    public function pullRequest(string $repository, int $number): FinalizedPullRequest
    {
        return $this->pullRequest;
    }

    public function branchHead(string $repository, string $branch): string
    {
        return $this->branchHeads[$branch];
    }

    /** @return list<string> */
    public function branches(string $repository): array
    {
        return array_keys($this->branchHeads);
    }

    public function publishHistorySynchronization(HistorySyncRequest $request): HistorySynchronization
    {
        $this->lastHistoryRequest = $request;
        $this->historyRequests[] = $request;
        return $this->publishedHistory ?? throw new \RuntimeException('No history publication fixture configured.');
    }
}
