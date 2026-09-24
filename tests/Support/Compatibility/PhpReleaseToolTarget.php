<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreCode\ReleaseTool\Tests\Support\Compatibility;

use LogicException;
use Symfony\Component\Process\Process;

final readonly class PhpReleaseToolTarget implements ReleaseCompatibilityTarget
{
    public function __construct(private string $repositoryRoot)
    {
    }

    public function checkAuthorization(
        string $repository,
        string $actor,
        string $minimum,
        string $apiUrl,
        array $environment = [],
    ): Process {
        return $this->run([
            'release:authorization',
            '--repository', $repository,
            '--actor', $actor,
            '--minimum', $minimum,
        ], ['GITHUB_API_URL' => $apiUrl] + $environment);
    }

    public function selectStable(string $repository, string $branch, array $environment = []): Process
    {
        return $this->run([
            'release:stable-select',
            '--repository', $repository,
            '--branch', $branch,
        ], $environment);
    }

    public function validateArtifact(
        string $artifact,
        string $appName,
        string $version,
        array $environment = [],
    ): Process {
        return $this->run([
            'artifact:validate-package',
            '--artifact', $artifact,
            '--app-name', $appName,
            '--version', $version,
        ], $environment);
    }

    public function restoreArtifact(
        string $repository,
        string $name,
        string $expectedHeadSha,
        string $destination,
        string $apiUrl,
        ?string $expectedEvent = null,
        ?string $expectedWorkflowPath = null,
        array $environment = [],
    ): Process {
        $arguments = [
            'artifact:restore',
            '--repository', $repository,
            '--name', $name,
            '--expected-head-sha', $expectedHeadSha,
            '--destination', $destination,
        ];
        if ($expectedEvent !== null) {
            $arguments[] = '--expected-event';
            $arguments[] = $expectedEvent;
        }
        if ($expectedWorkflowPath !== null) {
            $arguments[] = '--expected-workflow-path';
            $arguments[] = $expectedWorkflowPath;
        }

        return $this->run($arguments, ['GITHUB_API_URL' => $apiUrl] + $environment);
    }

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
    ): Process {
        throw new LogicException('PHP release-note compatibility is implemented in the final #57 slice.');
    }

    /** @param list<string> $arguments @param array<string, string> $environment */
    private function run(array $arguments, array $environment): Process
    {
        $process = new Process(
            ['php', $this->repositoryRoot . '/bin/release-tool', ...$arguments],
            $this->repositoryRoot,
            $environment,
        );
        $process->run();

        return $process;
    }
}
