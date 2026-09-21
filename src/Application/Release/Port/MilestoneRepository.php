<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Port;

use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneWorkItem;

interface MilestoneRepository
{
    /** @return list<MilestoneInfo> */
    public function openMilestones(string $repository): array;

    /** @return list<MilestoneInfo> */
    public function closedMilestones(string $repository): array;

    /** @return list<MilestoneWorkItem> */
    public function openItems(string $repository, int $milestoneNumber): array;

    public function renameMilestone(string $repository, int $milestoneNumber, string $title): MilestoneInfo;

    public function createMilestone(string $repository, string $title): MilestoneInfo;

    public function moveItem(string $repository, int $itemNumber, int $milestoneNumber): void;

    public function closeMilestone(string $repository, int $milestoneNumber): MilestoneInfo;
}
