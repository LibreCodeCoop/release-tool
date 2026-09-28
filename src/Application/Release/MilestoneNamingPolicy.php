<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;
use LibreCode\ReleaseTool\Domain\Version\Version;

final class MilestoneNamingPolicy
{
    public function title(
        ConsumerConfig $config,
        ReleaseChannel $channel,
        int $nextcloudMajor,
        int $appMajor,
        Version $version,
    ): string {
        $template = $channel === ReleaseChannel::Final ? $config->patchMilestone : $config->rcMilestone;
        return strtr($template, [
            '{nextcloud}' => (string) $nextcloudMajor,
            '{major}' => (string) $appMajor,
            '{version}' => (string) $version,
        ]);
    }

    public function matches(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }

        return $this->normalize($expected) === $this->normalize($actual);
    }

    private function normalize(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/^[\\p{So}\\p{Sk}\\p{Cf}\\s]+|[\\p{So}\\p{Sk}\\p{Cf}\\s]+$/u', '', $title) ?? $title;

        return trim(preg_replace('/\\s+/u', ' ', $title) ?? $title);
    }
}
