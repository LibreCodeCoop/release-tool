<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseDraftRepository;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\MilestoneTransition;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Release\ReleaseDraft;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final readonly class ReleaseDrafter
{
    public function __construct(
        private GitRepository $git,
        private ReleaseDraftRepository $github,
    ) {
    }

    public function prepare(
        ConsumerConfig $config,
        PreparedRelease $prepared,
        MilestoneTransition $milestone,
    ): ReleaseDraft {
        if ($milestone->preparedReleaseId !== $prepared->id) {
            throw new DomainException('MilestoneTransition does not belong to this PreparedRelease.');
        }
        if ($milestone->finalTitle !== $prepared->version) {
            throw new DomainException('MilestoneTransition final title does not match the prepared release version.');
        }

        $branchHead = $this->github->branchHead($prepared->repository, $prepared->branch);
        if ($branchHead !== $prepared->finalSha && !$this->git->isAncestor($prepared->finalSha, $branchHead)) {
            throw new DomainException(sprintf(
                'Release branch no longer contains finalized release %s; current head is %s.',
                $prepared->finalSha,
                $branchHead,
            ));
        }

        foreach ($prepared->releaseFileDigests as $path => $digest) {
            $actual = hash('sha256', $this->git->readFile($prepared->finalSha, $path));
            if (!hash_equals($digest, $actual)) {
                throw new DomainException(sprintf('Final release file digest changed: %s', $path));
            }
        }
        if (!hash_equals($prepared->changelogSha256, hash('sha256', $prepared->changelogSection))) {
            throw new DomainException('PreparedRelease changelog section digest is invalid.');
        }

        $merger = $this->github->pullRequestMerger(
            $prepared->repository,
            $prepared->releasePullRequestNumber,
        );
        $permission = $this->github->permission($prepared->repository, $merger);
        if ($this->permissionRank($permission) < $this->permissionRank($config->mergeMinPermission)) {
            throw new DomainException(sprintf(
                'Release PR merger %s has %s permission; %s is required.',
                $merger,
                $permission,
                $config->mergeMinPermission,
            ));
        }

        $tagTarget = $this->github->tagTarget($prepared->repository, $prepared->tagName);
        if ($tagTarget !== null && $tagTarget !== $prepared->finalSha) {
            throw new DomainException(sprintf(
                'Existing tag %s points to %s instead of %s.',
                $prepared->tagName,
                $tagTarget,
                $prepared->finalSha,
            ));
        }

        $body = $this->body($config, $prepared, $milestone);
        $prerelease = $prepared->channel !== ReleaseChannel::Final;
        $existing = $this->github->releaseByTag($prepared->repository, $prepared->tagName);
        if ($existing !== null && !$existing->draft) {
            throw new DomainException(sprintf(
                'Release %s is already published and will not be modified.',
                $prepared->tagName,
            ));
        }

        $draft = $existing === null
            ? $this->github->createDraft(
                $prepared->repository,
                $prepared->tagName,
                $prepared->finalSha,
                $prepared->version,
                $body,
                $prerelease,
            )
            : $this->github->updateDraft(
                $prepared->repository,
                $existing->id,
                $prepared->tagName,
                $prepared->finalSha,
                $prepared->version,
                $body,
                $prerelease,
            );

        if (!$draft->draft) {
            throw new DomainException('GitHub returned a non-draft release from draft preparation.');
        }
        if ($draft->tagName !== $prepared->tagName || $draft->targetCommitish !== $prepared->finalSha) {
            throw new DomainException('GitHub draft identity does not match the finalized release.');
        }
        if ($draft->prerelease !== $prerelease || $draft->body !== $body) {
            throw new DomainException('GitHub draft contents do not match the requested finalized artifacts.');
        }

        $bodyDigest = hash('sha256', $body);
        $id = hash('sha256', json_encode([
            $prepared->id,
            $milestone->id,
            $draft->id,
            $draft->url,
            $draft->tagName,
            $prepared->finalSha,
            $prerelease,
            $bodyDigest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new ReleaseDraft(
            $id,
            $prepared->id,
            $milestone->id,
            $draft->id,
            $draft->url,
            $draft->tagName,
            $prepared->finalSha,
            $prerelease,
            $bodyDigest,
            true,
            true,
        );
    }

    private function body(
        ConsumerConfig $config,
        PreparedRelease $prepared,
        MilestoneTransition $milestone,
    ): string {
        $previous = $this->git->previousRelease(
            $prepared->finalSha,
            $config->tagPrefix,
            $config->initialRef,
            $prepared->tagName,
        );
        $milestoneSeparator = str_contains($milestone->releasedMilestoneUrl, '?') ? '&' : '?';
        $milestoneUrl = $milestone->releasedMilestoneUrl . $milestoneSeparator . 'closed=1';
        $compareUrl = sprintf(
            'https://github.com/%s/compare/%s...%s',
            $prepared->repository,
            rawurlencode($previous->comparisonRef),
            rawurlencode($prepared->tagName),
        );

        $contributors = $this->contributors($prepared);

        return $this->renderChangelogSection($prepared->changelogSection)
            . $contributors
            . "\n\nMilestone: [" . $prepared->tagName . '](' . $milestoneUrl . ')'
            . "\n\n**Full Changelog**: " . $compareUrl;
    }

    private function contributors(PreparedRelease $prepared): string
    {
        preg_match_all(
            '/\[#(?<number>\d+)\]\(https:\/\/github\.com\/[^)]+\/pull\/\d+\)/',
            $prepared->changelogSection,
            $matches,
        );

        $contributors = [];
        foreach ($matches['number'] ?? [] as $number) {
            $login = $this->github->pullRequestAuthor($prepared->repository, (int) $number);
            if ($this->isBot($login) || in_array($login, $contributors, true)) {
                continue;
            }
            $contributors[] = $login;
        }

        if ($contributors === []) {
            return '';
        }

        $mentions = array_map(static fn (string $login): string => '@' . $login, $contributors);
        $last = array_pop($mentions);
        $joined = $mentions === []
            ? $last
            : implode(', ', $mentions) . ' and ' . $last;

        return "\n\n### Contributors\n\nThanks to " . $joined . ' for contributing to this release.';
    }

    private function isBot(string $login): bool
    {
        return str_ends_with(strtolower($login), '[bot]')
            || in_array(strtolower($login), ['dependabot', 'github-actions'], true);
    }

    private function renderChangelogSection(string $section): string
    {
        $section = rtrim($section);

        return preg_replace('/^(### .+)\n\n(?=- )/m', "$1\n", $section) ?? $section;
    }

    private function permissionRank(string $permission): int
    {
        return match ($permission) {
            'read' => 0,
            'triage' => 1,
            'write' => 2,
            'maintain' => 3,
            'admin' => 4,
            default => throw new DomainException(sprintf('Unsupported repository permission: %s', $permission)),
        };
    }
}
