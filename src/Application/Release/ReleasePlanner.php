<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\GitHubRepository;
use LibreCode\ReleaseTool\Application\Release\Port\GitRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Application\Release\ReadModel\PullRequestInfo;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\BackportBlocker;
use LibreCode\ReleaseTool\Domain\Release\ConventionalTitle;
use LibreCode\ReleaseTool\Domain\Release\ConventionalTitleParser;
use LibreCode\ReleaseTool\Domain\Release\ReleaseActivity;
use LibreCode\ReleaseTool\Domain\Release\ReleaseItem;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Security\SecurityReleasePolicy;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;
use LibreCode\ReleaseTool\Domain\Version\VersionPolicy;
use LibreCode\ReleaseTool\Domain\Version\VersionTransitionPolicy;

final readonly class ReleasePlanner implements ReleasePlanning
{
    public function __construct(
        private GitRepository $git,
        private GitHubRepository $github,
        private ReleaseMetadataReader $metadataReader,
        private VersionPolicy $versionPolicy = new VersionPolicy(),
        private VersionTransitionPolicy $versionTransitions = new VersionTransitionPolicy(),
        private ConventionalTitleParser $titleParser = new ConventionalTitleParser(),
        private SecurityReleasePolicy $securityPolicy = new SecurityReleasePolicy(),
        private ReleaseLineResolver $releaseLine = new ReleaseLineResolver(),
        private MilestoneNamingPolicy $milestoneNaming = new MilestoneNamingPolicy(),
    ) {
    }

    public function plan(ConsumerConfig $config, PlanReleaseInput $input): ReleasePlan
    {
        $branchHead = $this->git->branchHead($input->branch);
        $baseSha = $input->ref !== null ? $this->git->resolve($input->ref) : $branchHead;
        if (!$this->git->isAncestor($baseSha, $branchHead)) {
            throw new DomainException('Planning ref must be equal to or an ancestor of the selected branch head.');
        }

        $repository = $this->repositoryIdentity($config);
        $metadata = $this->metadataReader->read($config, $baseSha);
        $nextcloudMajor = $this->releaseLine->nextcloudMajor($config, $input->branch, $metadata->nextcloudMin, $metadata->nextcloudMax);
        $appMajor = $metadata->version->major;

        $previous = $this->git->previousRelease($baseSha, $config->tagPrefix, $config->initialRef);
        if (!$this->git->isAncestor($previous->sha, $baseSha)) {
            throw new DomainException('Previous release baseline is not an ancestor of the planning base.');
        }

        [$activity, $activityData, $warnings] = $this->releaseActivity(
            $repository,
            $input->branch,
            $previous->sha,
            $baseSha,
        );
        if (!$activity->hasReleasableActivity()) {
            throw new DomainException('No releasable activity exists after the previous release.');
        }

        $proposed = $this->proposedVersion($config, $metadata->version, $activity, $input);
        if ($proposed->major !== $appMajor) {
            throw new DomainException('A release plan cannot change the app major implicitly.');
        }

        $targetTag = $config->tagPrefix . (string) $proposed;
        if ($this->git->tagExists($targetTag) || $this->github->releaseExists($repository, $targetTag)) {
            throw new DomainException(sprintf('Target tag or release already exists: %s', $targetTag));
        }

        $changelogTarget = str_replace('{major}', (string) $appMajor, $config->changelogPath);
        try {
            $this->git->readFile($baseSha, $changelogTarget);
        } catch (\Throwable $exception) {
            throw new DomainException(
                sprintf('Configured changelog target does not exist at planning base: %s', $changelogTarget),
                0,
                $exception,
            );
        }

        $milestoneTitle = $this->milestoneNaming->title($config, $input->channel, $nextcloudMajor, $appMajor, $proposed);
        $milestone = null;
        foreach ($this->github->openMilestones($repository) as $candidate) {
            if ($candidate->title === $milestoneTitle) {
                $milestone = [
                    'number' => $candidate->number,
                    'title' => $candidate->title,
                    'url' => $candidate->url,
                ];
                break;
            }
        }
        if ($milestone === null) {
            $warnings[] = sprintf('Expected open milestone not found: %s', $milestoneTitle);
        }

        [$blockers, $otherOpenPullRequests] = $this->openPullRequestState($repository, $input->branch);
        if ($otherOpenPullRequests !== []) {
            $warnings[] = sprintf(
                '%d other open pull request(s) target %s; they do not block this release.',
                count($otherOpenPullRequests),
                $input->branch,
            );
        }
        if ($blockers !== []) {
            $warnings[] = $input->ignoreOpenBackport
                ? sprintf('Explicit override ignores %d open backport blocker(s).', count($blockers))
                : sprintf('%d open backport pull request(s) block the selected stable line.', count($blockers));
        }

        $publicText = $this->securityPolicy->publicText($input->mode, $input->safePublicText);
        $ready = $milestone !== null && ($blockers === [] || $input->ignoreOpenBackport);
        sort($warnings);

        $idData = [
            'repository' => $repository,
            'branch' => $input->branch,
            'base' => $baseSha,
            'previous' => [$previous->tag, $previous->sha, $previous->comparisonRef],
            'version' => [(string) $metadata->version, (string) $proposed, $input->channel->value],
            'activity' => $activityData,
            'milestone' => $milestone,
            'backports' => array_map(
                static fn (BackportBlocker $item): array => [$item->number, $item->title, $item->url],
                $blockers,
            ),
            'mode' => $input->mode->value,
            'public_text' => $publicText->text,
            'overrides' => [$input->ignoreOpenBackport, $input->createFollowUpMilestone],
        ];
        $id = hash('sha256', json_encode($idData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new ReleasePlan(
            $id,
            $repository,
            $config->appId,
            $input->branch,
            $nextcloudMajor,
            $appMajor,
            $baseSha,
            $previous->tag,
            $previous->sha,
            $previous->comparisonRef,
            (string) $metadata->version,
            (string) $proposed,
            $input->versionOverride !== null ? (string) $proposed : null,
            $input->channel,
            $input->versionOverride !== null ? 'explicit-override' : $this->versionPolicy->bumpReason($activity),
            $activityData,
            $changelogTarget,
            $milestone,
            $blockers,
            $input->ignoreOpenBackport,
            $input->createFollowUpMilestone,
            $input->mode,
            $publicText,
            $warnings,
            $ready,
        );
    }

    private function repositoryIdentity(ConsumerConfig $config): string
    {
        $gitRepository = $this->git->repositoryIdentity();
        $environmentRepository = getenv('GITHUB_REPOSITORY') ?: null;
        $repository = $config->repository ?? $gitRepository ?? $environmentRepository;

        if ($repository === null) {
            throw new DomainException('Repository identity is unavailable; configure repository explicitly.');
        }

        foreach ([$gitRepository, $environmentRepository] as $candidate) {
            if ($candidate !== null && strcasecmp($repository, $candidate) !== 0) {
                throw new DomainException(sprintf(
                    'Repository identity mismatch: configured %s, observed %s.',
                    $repository,
                    $candidate,
                ));
            }
        }

        return $repository;
    }

    /**
     * @return array{ReleaseActivity, list<array<string, mixed>>, list<string>}
     */
    private function releaseActivity(
        string $repository,
        string $branch,
        string $previousSha,
        string $baseSha,
    ): array {
        $items = [];
        $data = [];
        $warnings = [];
        $pullRequestCommitShas = [];

        $pullRequests = array_values(array_filter(
            $this->github->closedPullRequests($repository, $branch),
            fn (PullRequestInfo $pullRequest): bool => $this->pullRequestInRange($pullRequest, $previousSha, $baseSha),
        ));
        usort($pullRequests, static fn (PullRequestInfo $a, PullRequestInfo $b): int => $a->number <=> $b->number);

        foreach ($pullRequests as $pullRequest) {
            $parsed = $this->titleParser->parse($pullRequest->title);
            $kind = $this->pullRequestKind($pullRequest, $parsed);
            $backport = $this->isBackportPullRequest($pullRequest);
            $maintenance = $this->isMaintenance($parsed);

            $item = new ReleaseItem(
                kind: $kind,
                title: $pullRequest->title,
                pullRequestNumber: $pullRequest->number,
                conventionalType: $parsed->type,
                labels: $pullRequest->labels,
                url: $pullRequest->url,
                conventionalScope: $parsed->scope,
                backport: $backport,
                maintenance: $maintenance,
            );
            $items[] = $item;

            if ($pullRequest->mergeCommitSha !== null) {
                $pullRequestCommitShas[$pullRequest->mergeCommitSha] = true;
            }
            foreach ($this->github->pullRequestCommitShas($repository, $pullRequest->number) as $commitSha) {
                $pullRequestCommitShas[$commitSha] = true;
            }

            $data[] = [
                'kind' => $kind,
                'title' => $pullRequest->title,
                'subject' => $parsed->subject,
                'pull_request' => $pullRequest->number,
                'type' => $parsed->type,
                'scope' => $parsed->scope,
                'url' => $pullRequest->url,
                'backport' => $backport,
                'maintenance' => $maintenance,
            ];

            if ($parsed->type === null) {
                $warnings[] = sprintf('PR #%d title is not Conventional Commit formatted.', $pullRequest->number);
            }
            if ($parsed->breaking || preg_match('/(^|\R)BREAKING CHANGE:/i', $pullRequest->body) === 1) {
                $warnings[] = sprintf(
                    'PR #%d declares a breaking change; app major promotion is manual and was not applied.',
                    $pullRequest->number,
                );
            }
        }

        $directTranslationSeen = false;
        foreach ($this->git->commitsBetween($previousSha, $baseSha) as $commit) {
            if (isset($pullRequestCommitShas[$commit->sha])) {
                continue;
            }

            $parsed = $this->titleParser->parse($commit->subject);
            $kind = $this->directCommitKind($commit->subject, $parsed, $commit->paths);
            if ($kind !== 'translation' || $directTranslationSeen) {
                continue;
            }

            $items[] = new ReleaseItem(
                kind: 'translation',
                title: 'Update translations',
            );
            $data[] = [
                'kind' => 'translation',
                'title' => 'Update translations',
                'subject' => 'Update translations',
                'commit' => $commit->sha,
                'type' => null,
                'scope' => null,
                'backport' => false,
                'maintenance' => false,
            ];
            $directTranslationSeen = true;
        }

        return [new ReleaseActivity($items), $data, $warnings];
    }

    private function pullRequestInRange(PullRequestInfo $pullRequest, string $previousSha, string $baseSha): bool
    {
        if (!$pullRequest->isMerged() || $pullRequest->mergeCommitSha === null) {
            return false;
        }

        return $this->git->isAncestor($pullRequest->mergeCommitSha, $baseSha)
            && !$this->git->isAncestor($pullRequest->mergeCommitSha, $previousSha);
    }

    private function pullRequestKind(PullRequestInfo $pullRequest, ConventionalTitle $parsed): string
    {
        if (
            in_array($parsed->scope, ['deps', 'dependencies'], true)
            || str_contains(strtolower($pullRequest->author), 'dependabot')
            || preg_match('/\bdeps?\b|dependenc/i', $pullRequest->title) === 1
        ) {
            return 'dependency';
        }

        if (
            in_array($parsed->scope, ['l10n', 'i18n', 'translation'], true)
            || preg_match('/translat|\bl10n\b|\bi18n\b/i', $pullRequest->title) === 1
        ) {
            return 'translation';
        }

        return 'pull_request';
    }

    /**
     * @param list<string> $paths
     */
    private function directCommitKind(string $subject, ConventionalTitle $parsed, array $paths): string
    {
        if (
            in_array($parsed->scope, ['deps', 'dependencies'], true)
            || preg_match('/\bdeps?\b|dependenc/i', $subject) === 1
        ) {
            return 'dependency';
        }

        $translationPaths = $paths !== []
            && count(array_filter($paths, static fn (string $path): bool => str_starts_with($path, 'l10n/'))) === count($paths);
        if (
            $translationPaths
            || in_array($parsed->scope, ['l10n', 'i18n', 'translation'], true)
            || preg_match('/translat|\bl10n\b|\bi18n\b/i', $subject) === 1
        ) {
            return 'translation';
        }

        return 'direct_commit';
    }

    private function isBackportPullRequest(PullRequestInfo $pullRequest): bool
    {
        return preg_match(
            '/^\[stable\d+\]|^backport(?:\([^)]*\))?:|\bbackport\b/i',
            $pullRequest->title . "\n" . implode(' ', $pullRequest->labels),
        ) === 1;
    }

    private function isMaintenance(ConventionalTitle $parsed): bool
    {
        if (in_array($parsed->type, ['ci', 'chore', 'build', 'test', 'docs'], true)) {
            return true;
        }

        return in_array(
            $parsed->scope,
            ['release', 'ci', 'workflow', 'workflows', 'tooling', 'ops', 'dev', 'tests'],
            true,
        );
    }

    private function proposedVersion(
        ConsumerConfig $config,
        Version $current,
        ReleaseActivity $activity,
        PlanReleaseInput $input,
    ): Version {
        if ($input->versionOverride === null) {
            return $this->versionPolicy->propose($current, $activity, $input->channel);
        }

        $value = trim($input->versionOverride);
        if ($config->tagPrefix !== '' && str_starts_with($value, $config->tagPrefix)) {
            $value = substr($value, strlen($config->tagPrefix));
        }

        $override = Version::parse($value);
        $this->versionTransitions->validateOverride($current, $override, $input->channel);

        return $override;
    }

    /**
     * @return array{list<BackportBlocker>, list<PullRequestInfo>}
     */
    private function openPullRequestState(string $repository, string $branch): array
    {
        $blockers = [];
        $others = [];

        foreach ($this->github->openPullRequests($repository, $branch) as $pullRequest) {
            $labelText = implode(' ', $pullRequest->labels);
            $isBackport = preg_match(
                '/\bbackport\b|cherry.?pick|\[stable\d+\]/i',
                $pullRequest->title . "\n" . $pullRequest->body . "\n" . $labelText,
            ) === 1;

            if ($isBackport) {
                $blockers[] = new BackportBlocker($pullRequest->number, $pullRequest->title, $pullRequest->url);
            } else {
                $others[] = $pullRequest;
            }
        }

        usort($blockers, static fn (BackportBlocker $a, BackportBlocker $b): int => $a->number <=> $b->number);
        usort($others, static fn (PullRequestInfo $a, PullRequestInfo $b): int => $a->number <=> $b->number);

        return [$blockers, $others];
    }
}
