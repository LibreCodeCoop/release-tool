<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Domain\Changelog\ChangelogPolicy;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\FileChange;
use LibreCode\ReleaseTool\Domain\Release\ReleaseActivity;
use LibreCode\ReleaseTool\Domain\Release\ReleaseItem;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use LibreCode\ReleaseTool\Domain\Version\Version;

final readonly class ReleasePreparer
{
    public function __construct(
        private GitRepository $git,
        private ReleaseFileUpdater $fileUpdater = new ReleaseFileUpdater(),
        private ChangelogPolicy $changelogPolicy = new ChangelogPolicy(),
    ) {
    }

    public function prepare(ConsumerConfig $config, ReleasePlan $plan): ReleasePreparationResult
    {
        if (!$plan->ready) {
            throw new DomainException('ReleasePlan is not ready for mutating preparation.');
        }

        $branchHead = $this->git->branchHead($plan->branch);
        if ($branchHead !== $plan->planningBaseSha) {
            throw new DomainException(sprintf(
                'ReleasePlan is stale: %s now points to %s, expected %s.',
                $plan->branch,
                $branchHead,
                $plan->planningBaseSha,
            ));
        }

        if ($config->repository !== null && strcasecmp($config->repository, $plan->repository) !== 0) {
            throw new DomainException(sprintf(
                'ReleasePlan repository %s does not match consumer configuration %s.',
                $plan->repository,
                $config->repository,
            ));
        }

        $version = Version::parse($plan->proposedVersion);
        if ($version->major !== $plan->appMajor) {
            throw new DomainException('ReleasePlan app major does not match proposed version.');
        }

        $expectedChangelog = str_replace('{major}', (string) $plan->appMajor, $config->changelogPath);
        if ($expectedChangelog !== $plan->changelogTarget) {
            throw new DomainException(sprintf(
                'ReleasePlan changelog target %s does not match consumer configuration %s.',
                $plan->changelogTarget,
                $expectedChangelog,
            ));
        }

        $allowedPaths = array_values(array_unique([
            $plan->changelogTarget,
            $config->versionSource,
            ...$config->versionMirrors,
        ]));

        $changes = [];
        $snapshots = [];

        $currentChangelog = $this->git->readFile($plan->planningBaseSha, $plan->changelogTarget);
        $activity = $this->releaseActivity($plan);
        $changelog = $this->changelogPolicy->prepare(
            $version,
            $activity,
            $config->changelogPath,
            $currentChangelog,
            $this->git->commitDate($plan->planningBaseSha),
        );

        if ($changelog->targetPath !== $plan->changelogTarget) {
            throw new DomainException('Changelog policy produced an unexpected target path.');
        }

        $this->addChange(
            $changes,
            $snapshots,
            $plan->changelogTarget,
            $currentChangelog,
            $changelog->content,
        );

        foreach (array_values(array_unique([$config->versionSource, ...$config->versionMirrors])) as $path) {
            $before = $this->git->readFile($plan->planningBaseSha, $path);
            $after = $this->fileUpdater->update($path, $before, $plan->proposedVersion);
            $this->addChange($changes, $snapshots, $path, $before, $after);
        }

        if ($changes === []) {
            throw new DomainException('Release preparation produced no file changes.');
        }

        foreach ($changes as $change) {
            if (!in_array($change->path, $allowedPaths, true)) {
                throw new DomainException(sprintf(
                    'Release preparation attempted an unexpected file: %s',
                    $change->path,
                ));
            }
        }

        usort($changes, static fn (FileChange $left, FileChange $right): int => $left->path <=> $right->path);
        ksort($snapshots);

        $generatedBranch = sprintf(
            'release-tool/%s/%s/%s',
            $this->safeRefPart($plan->branch),
            $this->safeRefPart($plan->proposedVersion),
            substr($plan->id, 0, 12),
        );
        $marker = sprintf(
            '<!-- release-tool:preparation plan=%s version=%s -->',
            $plan->id,
            $plan->proposedVersion,
        );

        $identity = [
            'release_plan_id' => $plan->id,
            'repository' => $plan->repository,
            'target_branch' => $plan->branch,
            'planning_base_sha' => $plan->planningBaseSha,
            'version' => $plan->proposedVersion,
            'channel' => $plan->channel->value,
            'mode' => $plan->mode->value,
            'changes' => array_map(
                static fn (FileChange $change): array => [
                    $change->path,
                    $change->beforeSha256,
                    $change->afterSha256,
                ],
                $changes,
            ),
            'changelog_sha256' => hash('sha256', $changelog->releaseSection),
            'generated_branch' => $generatedBranch,
            'pr_marker' => $marker,
        ];
        $id = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $preparation = new ReleasePreparation(
            $id,
            $plan->id,
            $plan->repository,
            $plan->branch,
            $plan->planningBaseSha,
            $plan->proposedVersion,
            $plan->channel,
            $plan->mode,
            $changes,
            $changelog->releaseSection,
            hash('sha256', $changelog->releaseSection),
            $generatedBranch,
            $marker,
            null,
            null,
        );

        return new ReleasePreparationResult(
            $preparation,
            $this->renderExactSnapshots($snapshots),
        );
    }

    private function releaseActivity(ReleasePlan $plan): ReleaseActivity
    {
        $items = [];
        foreach ($plan->activity as $entry) {
            $kind = isset($entry['kind']) && is_string($entry['kind']) ? $entry['kind'] : 'direct_commit';
            $title = isset($entry['title']) && is_string($entry['title']) ? $entry['title'] : '';
            $pullRequest = isset($entry['pull_request']) && is_int($entry['pull_request'])
                ? $entry['pull_request']
                : null;
            $type = isset($entry['type']) && is_string($entry['type'])
                ? $entry['type']
                : null;
            $scope = isset($entry['scope']) && is_string($entry['scope'])
                ? $entry['scope']
                : null;
            $url = isset($entry['url']) && is_string($entry['url'])
                ? $entry['url']
                : null;
            $backport = isset($entry['backport']) && $entry['backport'] === true;
            $maintenance = isset($entry['maintenance']) && $entry['maintenance'] === true;

            if ($title === '') {
                throw new DomainException('ReleasePlan activity contains an item without a title.');
            }

            $items[] = new ReleaseItem(
                kind: $kind,
                title: $title,
                pullRequestNumber: $pullRequest,
                conventionalType: $type,
                url: $url,
                conventionalScope: $scope,
                backport: $backport,
                maintenance: $maintenance,
            );
        }

        return new ReleaseActivity($items);
    }

    /**
     * @param list<FileChange> $changes
     * @param array<string, array{before: string, after: string}> $snapshots
     */
    private function addChange(
        array &$changes,
        array &$snapshots,
        string $path,
        string $before,
        string $after,
    ): void {
        if ($before === $after) {
            return;
        }

        $changes[] = new FileChange(
            $path,
            hash('sha256', $before),
            hash('sha256', $after),
            $after,
        );
        $snapshots[$path] = ['before' => $before, 'after' => $after];
    }

    /**
     * @param array<string, array{before: string, after: string}> $snapshots
     */
    private function renderExactSnapshots(array $snapshots): string
    {
        $parts = [];
        foreach ($snapshots as $path => $snapshot) {
            $parts[] = sprintf(
                "=== %s ===\n--- before\n%s\n--- after\n%s",
                $path,
                rtrim($snapshot['before'], "\n"),
                rtrim($snapshot['after'], "\n"),
            );
        }

        return implode("\n\n", $parts) . "\n";
    }

    private function safeRefPart(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-.');

        if ($value === '') {
            throw new DomainException('Cannot derive generated branch identity.');
        }

        return $value;
    }
}
