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
}
