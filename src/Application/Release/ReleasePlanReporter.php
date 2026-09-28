<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;

final class ReleasePlanReporter
{
    public function summary(ReleasePlan $plan, string $toolVersion): string
    {
        $lines = [
            '## Release plan',
            '',
            sprintf('- Tool: release-tool %s', $toolVersion),
            sprintf('- Plan: `%s`', $plan->id),
            sprintf('- Branch: `%s`', $plan->branch),
            sprintf('- Planning base: `%s`', $plan->planningBaseSha),
            sprintf('- Version: `%s` -> `%s`', $plan->currentVersion, $plan->proposedVersion),
            sprintf('- Channel: `%s`', $plan->channel->value),
            sprintf('- Ready: **%s**', $plan->ready ? 'yes' : 'no'),
        ];

        foreach ($plan->warnings as $warning) {
            $lines[] = '- Warning: ' . $this->singleLine($warning);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    public function githubAnnotations(ReleasePlan $plan): array
    {
        if ($plan->ready) {
            return [];
        }

        $milestone = $plan->milestone === null
            ? 'missing'
            : sprintf('%s (#%s)', $plan->milestone['title'], $plan->milestone['number']);

        $lines = [
            sprintf(
                '::error::Release plan is not ready (milestone=%s, backport-blockers=%d).',
                $this->singleLine($milestone),
                count($plan->backportBlockers),
            ),
        ];

        foreach ($plan->backportBlockers as $blocker) {
            $lines[] = sprintf(
                '::error::Open backport blocker #%d: %s',
                $blocker->number,
                $this->singleLine($blocker->title),
            );
        }

        foreach ($plan->warnings as $warning) {
            $lines[] = '::warning::' . $this->singleLine($warning);
        }

        return $lines;
    }

    private function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
