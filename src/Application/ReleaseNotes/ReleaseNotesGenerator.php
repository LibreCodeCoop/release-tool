<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\ReleaseNotes;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\ReleaseNotes\Port\AssociatedPullRequestRepository;
use LibreCode\ReleaseTool\Application\ReleaseNotes\Port\ReleaseNotesGitRepositoryFactory;

final readonly class ReleaseNotesGenerator
{
    public function __construct(
        private ReleaseNotesGitRepositoryFactory $gitRepositories,
        private AssociatedPullRequestRepository $pullRequests,
    ) {
    }

    public function generate(
        string $repository,
        string $branch,
        string $workingDirectory,
        string $serverUrl,
        string $fromRef,
        string $toRef,
        int $fallbackLimit,
    ): ReleaseNotesResult {
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1) {
            throw new InvalidArgumentException('repository must be in owner/name form');
        }
        if ($branch === '') {
            throw new InvalidArgumentException('branch is required');
        }
        if (!is_dir($workingDirectory)) {
            throw new InvalidArgumentException(sprintf('working directory does not exist: %s', $workingDirectory));
        }
        if ($fallbackLimit <= 0) {
            throw new InvalidArgumentException('fallback-limit must be greater than zero');
        }

        $git = $this->gitRepositories->create($workingDirectory);
        $commits = $git->commits($fromRef, $toRef === '' ? 'HEAD' : $toRef, $fallbackLimit);
        $seen = [];
        $lines = [];
        $pullRequestCount = 0;
        $commitFallbackCount = 0;
        $baseUrl = rtrim($serverUrl, '/');

        foreach ($commits as $sha) {
            $pullRequest = $this->choosePullRequest($this->pullRequests->forCommit($repository, $sha), $branch);
            if ($pullRequest !== null) {
                if (isset($seen[$pullRequest->number])) {
                    continue;
                }
                $seen[$pullRequest->number] = true;
                $title = self::sanitizeMarkdownText($pullRequest->title);
                if ($title === '') {
                    $title = sprintf('Pull request #%d', $pullRequest->number);
                }
                $url = sprintf('%s/%s/pull/%d', $baseUrl, $repository, $pullRequest->number);
                $lines[] = sprintf('- %s ([#%d](%s))', $title, $pullRequest->number, $url);
                ++$pullRequestCount;
                continue;
            }

            $subject = self::sanitizeMarkdownText($git->subject($sha));
            $lines[] = sprintf('- %s (`%s`)', $subject, substr($sha, 0, 7));
            ++$commitFallbackCount;
        }

        return new ReleaseNotesResult($lines, $pullRequestCount, $commitFallbackCount);
    }

    /** @param list<AssociatedPullRequest> $pullRequests */
    private function choosePullRequest(array $pullRequests, string $preferredBranch): ?AssociatedPullRequest
    {
        $merged = array_values(array_filter(
            $pullRequests,
            static fn (AssociatedPullRequest $pullRequest): bool => $pullRequest->mergedAt !== '',
        ));
        if ($merged === []) {
            return null;
        }

        $preferred = array_values(array_filter(
            $merged,
            static fn (AssociatedPullRequest $pullRequest): bool => $pullRequest->baseBranch === $preferredBranch,
        ));
        $candidates = $preferred === [] ? $merged : $preferred;
        usort(
            $candidates,
            static fn (AssociatedPullRequest $left, AssociatedPullRequest $right): int => $left->number <=> $right->number,
        );

        return $candidates[0];
    }

    public static function sanitizeMarkdownText(string $value): string
    {
        $normalized = str_replace("\r", "\n", $value);
        $parts = preg_split('/\n|\v|\f|\x{85}|\x{2028}|\x{2029}/u', $normalized);
        $normalized = trim(implode(' ', is_array($parts) ? $parts : [$normalized]));

        foreach (['\\', '`', '*', '_', '{', '}', '[', ']', '<', '>'] as $character) {
            $normalized = str_replace($character, '\\' . $character, $normalized);
        }

        return str_replace('@', "@\u{200B}", $normalized);
    }
}
