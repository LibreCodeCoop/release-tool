<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\MilestoneRepository;
use LibreCode\ReleaseTool\Application\Release\Port\ReleaseMetadataReader;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Release\MilestoneTransition;
use LibreCode\ReleaseTool\Domain\Release\MilestoneTransitionOperation;
use LibreCode\ReleaseTool\Domain\Release\MilestoneTransitionPlan;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Version\Version;

final readonly class MilestoneTransitioner
{
    public function __construct(
        private MilestoneRepository $milestones,
        private ReleaseMetadataReader $metadataReader,
        private ReleaseLineResolver $releaseLine = new ReleaseLineResolver(),
        private MilestoneNamingPolicy $naming = new MilestoneNamingPolicy(),
    ) {
    }

    public function plan(
        ConsumerConfig $config,
        PreparedRelease $prepared,
        bool $createFollowUp,
    ): MilestoneTransitionPlan {
        $metadata = $this->metadataReader->read($config, $prepared->finalSha);
        if ((string) $metadata->version !== $prepared->version) {
            throw new DomainException('PreparedRelease version does not match final release metadata.');
        }

        $version = Version::parse($prepared->version);
        $nextcloudMajor = $this->releaseLine->nextcloudMajor(
            $config,
            $prepared->branch,
            $metadata->nextcloudMin,
            $metadata->nextcloudMax,
        );
        $currentTitle = $this->naming->title(
            $config,
            $prepared->channel,
            $nextcloudMajor,
            $version->major,
            $version,
        );
        $finalTitle = $prepared->version;

        $closedFinal = $this->findByTitle($this->milestones->closedMilestones($prepared->repository), $finalTitle);
        if ($closedFinal !== null) {
            if ($createFollowUp && $this->findByTitle(
                $this->milestones->openMilestones($prepared->repository),
                $currentTitle,
            ) === null) {
                throw new DomainException(sprintf(
                    'Release milestone %s is already closed, but expected follow-up milestone %s is missing.',
                    $finalTitle,
                    $currentTitle,
                ));
            }

            return new MilestoneTransitionPlan(
                $this->planId($prepared, $closedFinal->number, $finalTitle, $createFollowUp, []),
                $prepared->id,
                $prepared->repository,
                $closedFinal->number,
                $closedFinal->url,
                $finalTitle,
                $finalTitle,
                $createFollowUp ? $currentTitle : null,
                [],
                true,
            );
        }

        $open = $this->milestones->openMilestones($prepared->repository);
        $source = $this->findByTitle($open, $currentTitle);
        if ($source === null) {
            throw new DomainException(sprintf('Expected open milestone not found: %s', $currentTitle));
        }
        if ($this->findByTitle($open, $finalTitle) !== null) {
            throw new DomainException(sprintf(
                'Milestone transition is ambiguous: both %s and %s are open.',
                $currentTitle,
                $finalTitle,
            ));
        }

        $items = $this->milestones->openItems($prepared->repository, $source->number);
        $operations = [new MilestoneTransitionOperation('rename_milestone', [
            'number' => $source->number,
            'from' => $currentTitle,
            'to' => $finalTitle,
        ])];

        if ($createFollowUp) {
            $operations[] = new MilestoneTransitionOperation('create_follow_up', [
                'title' => $currentTitle,
            ]);
            foreach ($items as $item) {
                $operations[] = new MilestoneTransitionOperation(
                    $item->pullRequest ? 'move_pull_request' : 'move_issue',
                    [
                        'number' => $item->number,
                        'url' => $item->url,
                        'to' => $currentTitle,
                    ],
                );
            }
        }

        $operations[] = new MilestoneTransitionOperation('close_milestone', [
            'number' => $source->number,
            'title' => $finalTitle,
        ]);

        return new MilestoneTransitionPlan(
            $this->planId($prepared, $source->number, $finalTitle, $createFollowUp, $operations),
            $prepared->id,
            $prepared->repository,
            $source->number,
            $source->url,
            $currentTitle,
            $finalTitle,
            $createFollowUp ? $currentTitle : null,
            $operations,
        );
    }

    public function apply(MilestoneTransitionPlan $plan): MilestoneTransition
    {
        if ($plan->alreadyApplied) {
            $followUp = $plan->followUpTitle === null ? null : $this->findByTitle(
                $this->milestones->openMilestones($plan->repository),
                $plan->followUpTitle,
            );
            return $this->result($plan, $followUp?->number, $followUp?->url, 0, 0, true);
        }

        $open = $this->milestones->openMilestones($plan->repository);
        $source = $this->findByNumber($open, $plan->releasedMilestoneNumber);
        if ($source === null || $source->title !== $plan->currentTitle) {
            throw new DomainException('Milestone state changed after planning; rerun dry-run before applying.');
        }

        $this->milestones->renameMilestone(
            $plan->repository,
            $plan->releasedMilestoneNumber,
            $plan->finalTitle,
        );

        $followUp = null;
        $movedIssues = 0;
        $movedPullRequests = 0;
        if ($plan->followUpTitle !== null) {
            $followUp = $this->findByTitle(
                $this->milestones->openMilestones($plan->repository),
                $plan->followUpTitle,
            ) ?? $this->milestones->createMilestone($plan->repository, $plan->followUpTitle);

            foreach ($this->milestones->openItems($plan->repository, $plan->releasedMilestoneNumber) as $item) {
                $this->milestones->moveItem($plan->repository, $item->number, $followUp->number);
                if ($item->pullRequest) {
                    ++$movedPullRequests;
                } else {
                    ++$movedIssues;
                }
            }
        }

        $closed = $this->milestones->closeMilestone($plan->repository, $plan->releasedMilestoneNumber);
        if ($closed->title !== $plan->finalTitle) {
            throw new DomainException('GitHub closed milestone with an unexpected title.');
        }

        return $this->result(
            $plan,
            $followUp?->number,
            $followUp?->url,
            $movedIssues,
            $movedPullRequests,
        );
    }

    /** @param list<\LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo> $milestones */
    private function findByTitle(array $milestones, string $title): ?\LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo
    {
        foreach ($milestones as $milestone) {
            if ($milestone->title === $title) {
                return $milestone;
            }
        }
        return null;
    }

    /** @param list<\LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo> $milestones */
    private function findByNumber(array $milestones, int $number): ?\LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo
    {
        foreach ($milestones as $milestone) {
            if ($milestone->number === $number) {
                return $milestone;
            }
        }
        return null;
    }

    /** @param list<MilestoneTransitionOperation> $operations */
    private function planId(
        PreparedRelease $prepared,
        int $milestoneNumber,
        string $finalTitle,
        bool $createFollowUp,
        array $operations,
    ): string {
        return hash('sha256', json_encode([
            $prepared->id,
            $milestoneNumber,
            $finalTitle,
            $createFollowUp,
            $operations,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function result(
        MilestoneTransitionPlan $plan,
        ?int $followUpNumber,
        ?string $followUpUrl,
        int $movedIssues,
        int $movedPullRequests,
        bool $alreadyApplied = false,
    ): MilestoneTransition {
        return new MilestoneTransition(
            hash('sha256', json_encode([
                $plan->id,
                $followUpNumber,
                $movedIssues,
                $movedPullRequests,
                $alreadyApplied,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            $plan->preparedReleaseId,
            $plan->releasedMilestoneNumber,
            $plan->releasedMilestoneUrl,
            $plan->finalTitle,
            $followUpNumber,
            $followUpUrl,
            $movedIssues,
            $movedPullRequests,
            $alreadyApplied,
        );
    }
}
