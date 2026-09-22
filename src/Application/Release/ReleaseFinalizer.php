<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseFinalizationRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Domain\Changelog\ChangelogHistorySynchronizer;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;

final readonly class ReleaseFinalizer
{
    public function __construct(
        private GitRepository $git,
        private ReleaseFinalizationRepository $github,
        private ReleaseMetadataReader $metadataReader,
        private ChangelogHistorySynchronizer $historySynchronizer = new ChangelogHistorySynchronizer(),
    ) {
    }

    public function finalize(
        ConsumerConfig $config,
        ReleasePreparation $preparation,
        bool $applyHistorySynchronization = false,
    ): PreparedRelease {
        if ($preparation->pullRequestNumber === null || $preparation->pullRequestUrl === null) {
            throw new DomainException('ReleasePreparation has no applied pull request identity.');
        }

        $pullRequest = $this->github->pullRequest(
            $preparation->repository,
            $preparation->pullRequestNumber,
        );
        if (!$pullRequest->merged || $pullRequest->mergeCommitSha === null) {
            throw new DomainException('Release preparation pull request is not merged.');
        }
        if ($pullRequest->baseBranch !== $preparation->targetBranch) {
            throw new DomainException('Merged release pull request base branch does not match ReleasePreparation.');
        }
        if ($pullRequest->url !== $preparation->pullRequestUrl) {
            throw new DomainException('Merged release pull request URL does not match ReleasePreparation.');
        }

        $allowedFiles = array_map(static fn ($change): string => $change->path, $preparation->fileChanges);
        $unexpectedFiles = array_values(array_diff($pullRequest->changedFiles, $allowedFiles));
        if ($unexpectedFiles !== []) {
            throw new DomainException(sprintf(
                'Merged release pull request contains unexpected files: %s',
                implode(', ', $unexpectedFiles),
            ));
        }

        $finalSha = $pullRequest->mergeCommitSha;
        $branchHead = $this->github->branchHead($preparation->repository, $preparation->targetBranch);
        if ($branchHead !== $finalSha) {
            throw new DomainException(sprintf(
                'Release branch advanced after merge: expected %s, found %s.',
                $finalSha,
                $branchHead,
            ));
        }

        $metadata = $this->metadataReader->read($config, $finalSha);
        if ((string) $metadata->version !== $preparation->version) {
            throw new DomainException(sprintf(
                'Merged release version is %s, expected %s.',
                (string) $metadata->version,
                $preparation->version,
            ));
        }

        $changelog = $this->git->readFile($finalSha, $this->changelogTarget($config, $metadata->version->major));
        $section = $this->extractChangelogSection($changelog, $preparation->version);
        $sectionDigest = hash('sha256', $section);

        $digests = [];
        foreach ($allowedFiles as $path) {
            $digests[$path] = hash('sha256', $this->git->readFile($finalSha, $path));
        }
        ksort($digests);

        $tagName = $config->tagPrefix . $preparation->version;
        $identity = [
            'release_plan_id' => $preparation->releasePlanId,
            'release_preparation_id' => $preparation->id,
            'repository' => $preparation->repository,
            'branch' => $preparation->targetBranch,
            'pull_request' => $preparation->pullRequestNumber,
            'final_sha' => $finalSha,
            'version' => $preparation->version,
            'tag_name' => $tagName,
            'channel' => $preparation->channel->value,
            'mode' => $preparation->mode->value,
            'changelog_sha256' => $sectionDigest,
            'release_files' => $digests,
        ];
        $id = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $history = $this->historySynchronization(
            $config,
            $preparation,
            $id,
            $section,
            $applyHistorySynchronization,
        );

        return new PreparedRelease(
            $id,
            $preparation->releasePlanId,
            $preparation->id,
            $preparation->repository,
            $preparation->targetBranch,
            $preparation->pullRequestNumber,
            $preparation->pullRequestUrl,
            $finalSha,
            $preparation->version,
            $tagName,
            $preparation->channel,
            $preparation->mode,
            $this->changelogTarget($config, $metadata->version->major),
            $section,
            $sectionDigest,
            $digests,
            $history,
        );
    }

    private function historySynchronization(
        ConsumerConfig $config,
        ReleasePreparation $preparation,
        string $preparedId,
        string $exactSection,
        bool $apply,
    ): HistorySynchronization {
        $targetPath = $this->changelogTarget($config, (int) explode('.', $preparation->version, 2)[0]);
        if ($preparation->targetBranch === $config->mainBranch) {
            return new HistorySynchronization(HistorySyncState::NotRequired, $config->mainBranch, $targetPath);
        }

        $targets = $this->historyTargets($config, $preparation->repository, $preparation->targetBranch);
        $mainResult = new HistorySynchronization(
            HistorySyncState::AlreadySynchronized,
            $config->mainBranch,
            $targetPath,
        );

        foreach ($targets as $targetBranch) {
            $targetSha = $this->github->branchHead($preparation->repository, $targetBranch);
            $current = $this->git->readFile($targetSha, $targetPath);
            $updated = $this->historySynchronizer->synchronize(
                $current,
                $preparation->version,
                $exactSection,
            );

            if ($updated === $current) {
                $result = new HistorySynchronization(
                    HistorySyncState::AlreadySynchronized,
                    $targetBranch,
                    $targetPath,
                );
            } else {
                $branch = sprintf(
                    'release-tool/history/%s/%s/%s',
                    preg_replace('/[^A-Za-z0-9._-]+/', '-', $preparation->version) ?: 'release',
                    preg_replace('/[^A-Za-z0-9._-]+/', '-', $targetBranch) ?: 'target',
                    substr($preparedId, 0, 12),
                );
                $marker = sprintf(
                    '<!-- release-tool:history prepared=%s version=%s target=%s -->',
                    $preparedId,
                    $preparation->version,
                    $targetBranch,
                );

                if (!$apply) {
                    $result = new HistorySynchronization(
                        HistorySyncState::Planned,
                        $targetBranch,
                        $targetPath,
                        $branch,
                    );
                } else {
                    $result = $this->github->publishHistorySynchronization(new HistorySyncRequest(
                        $preparation->repository,
                        $targetBranch,
                        $targetSha,
                        $targetPath,
                        $updated,
                        $branch,
                        $marker,
                        $preparation->version,
                    ));
                }
            }

            if ($targetBranch === $config->mainBranch) {
                $mainResult = $result;
            }
        }

        return $mainResult;
    }

    /** @return list<string> */
    private function historyTargets(ConsumerConfig $config, string $repository, string $sourceBranch): array
    {
        if (preg_match($this->stablePattern($config), $sourceBranch, $sourceMatch) !== 1) {
            throw new DomainException(sprintf(
                'Release branch %s does not match configured stable pattern.',
                $sourceBranch,
            ));
        }
        $sourceMajor = isset($sourceMatch['nextcloud']) ? (int) $sourceMatch['nextcloud'] : null;
        if ($sourceMajor === null) {
            throw new DomainException('Stable branch pattern must expose a nextcloud capture group.');
        }

        $stableTargets = [];
        foreach ($this->github->branches($repository) as $branch) {
            if (preg_match($this->stablePattern($config), $branch, $match) !== 1 || !isset($match['nextcloud'])) {
                continue;
            }
            $major = (int) $match['nextcloud'];
            if ($major > $sourceMajor) {
                $stableTargets[$major] = $branch;
            }
        }
        ksort($stableTargets, SORT_NUMERIC);

        return [...array_values($stableTargets), $config->mainBranch];
    }

    private function stablePattern(ConsumerConfig $config): string
    {
        return '~' . str_replace('~', '\\~', $config->stablePattern) . '~';
    }

    private function changelogTarget(ConsumerConfig $config, int $major): string
    {
        return str_replace('{major}', (string) $major, $config->changelogPath);
    }

    private function extractChangelogSection(string $content, string $version): string
    {
        $pattern = '/^## (?:\\[)?' . preg_quote($version, '/') . '(?:\\])?(?:\\s|$)[\\s\\S]*?(?=^## (?:\\[)?\\d+\\.\\d+\\.\\d+|\\z)/m';
        if (preg_match($pattern, $content, $match) !== 1) {
            throw new DomainException(sprintf('Merged changelog does not contain release %s.', $version));
        }

        return rtrim($match[0]);
    }
}
