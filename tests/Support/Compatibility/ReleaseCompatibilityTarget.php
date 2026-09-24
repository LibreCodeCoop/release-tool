<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreCode\ReleaseTool\Tests\Support\Compatibility;

use Symfony\Component\Process\Process;

interface ReleaseCompatibilityTarget
{
    /** @param array<string, string> $environment */
    public function checkAuthorization(
        string $repository,
        string $actor,
        string $minimum,
        string $apiUrl,
        array $environment = [],
    ): Process;

    /** @param array<string, string> $environment */
    public function selectStable(
        string $repository,
        string $branch,
        array $environment = [],
    ): Process;

    /** @param array<string, string> $environment */
    public function validateArtifact(
        string $artifact,
        string $appName,
        string $version,
        array $environment = [],
    ): Process;

    /** @param array<string, string> $environment */
    public function restoreArtifact(
        string $repository,
        string $name,
        string $expectedHeadSha,
        string $destination,
        string $apiUrl,
        ?string $expectedEvent = null,
        ?string $expectedWorkflowPath = null,
        array $environment = [],
    ): Process;

    /** @param array<string, string> $environment */
    public function releaseNotes(
        string $repository,
        string $branch,
        string $workingDirectory,
        string $apiUrl,
        string $serverUrl,
        string $fromRef,
        string $toRef,
        int $fallbackLimit,
        array $environment = [],
    ): Process;
}
