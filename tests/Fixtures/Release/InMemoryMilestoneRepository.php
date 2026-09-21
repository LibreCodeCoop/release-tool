<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Tests\Fixtures\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\MilestoneRepository;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneInfo;
use LibreCode\ReleaseTool\Application\Release\ReadModel\MilestoneWorkItem;

final class InMemoryMilestoneRepository implements MilestoneRepository
{
    /** @param list<MilestoneInfo> $open @param list<MilestoneInfo> $closed @param array<int, list<MilestoneWorkItem>> $items */
    public function __construct(
        public array $open,
        public array $closed = [],
        public array $items = [],
    ) {
    }

    public function openMilestones(string $repository): array { return array_values($this->open); }
    public function closedMilestones(string $repository): array { return array_values($this->closed); }
    public function openItems(string $repository, int $milestoneNumber): array { return $this->items[$milestoneNumber] ?? []; }

    public function renameMilestone(string $repository, int $milestoneNumber, string $title): MilestoneInfo
    {
        foreach ($this->open as $key => $milestone) {
            if ($milestone->number === $milestoneNumber) {
                return $this->open[$key] = new MilestoneInfo($milestoneNumber, $title, $milestone->url);
            }
        }
        throw new DomainException('Unknown open milestone.');
    }

    public function createMilestone(string $repository, string $title): MilestoneInfo
    {
        $number = 100 + count($this->open) + count($this->closed);
        $milestone = new MilestoneInfo($number, $title, 'https://example.test/milestones/' . $number);
        $this->open[] = $milestone;
        return $milestone;
    }

    public function moveItem(string $repository, int $itemNumber, int $milestoneNumber): void
    {
        foreach ($this->items as $source => $items) {
            foreach ($items as $key => $item) {
                if ($item->number === $itemNumber) {
                    unset($this->items[$source][$key]);
                    $this->items[$source] = array_values($this->items[$source]);
                    $this->items[$milestoneNumber][] = $item;
                    return;
                }
            }
        }
        throw new DomainException('Unknown milestone item.');
    }

    public function closeMilestone(string $repository, int $milestoneNumber): MilestoneInfo
    {
        foreach ($this->open as $key => $milestone) {
            if ($milestone->number === $milestoneNumber) {
                unset($this->open[$key]);
                $this->open = array_values($this->open);
                $this->closed[] = $milestone;
                return $milestone;
            }
        }
        throw new DomainException('Unknown milestone to close.');
    }
}
