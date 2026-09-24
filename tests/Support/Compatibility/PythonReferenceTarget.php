<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreCode\ReleaseTool\Tests\Support\Compatibility;

use Symfony\Component\Process\Process;

final readonly class PythonReferenceTarget implements ReleaseCompatibilityTarget
{
    public function __construct(private string $fixtureDirectory)
    {
    }

    public function checkAuthorization(
        string $repository,
        string $actor,
        string $minimum,
        string $apiUrl,
        array $environment = [],
    ): Process {
        return $this->run(
            'check_release_authorization.py',
            ['--repository', $repository, '--actor', $actor, '--minimum', $minimum, '--api-url', $apiUrl],
            $environment,
        );
    }

    public function selectStable(string $repository, string $branch, array $environment = []): Process
    {
        return $this->run(
            'release_stable_select.py',
            [],
            ['INPUT_REPOSITORY' => $repository, 'INPUT_BRANCH' => $branch] + $environment,
        );
    }

    public function validateArtifact(
        string $artifact,
        string $appName,
        string $version,
        array $environment = [],
    ): Process {
        return $this->run(
            'validate_release_artifact.py',
            ['--artifact', $artifact, '--app-name', $appName, '--version', $version],
            $environment,
        );
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
            '--repository', $repository,
            '--name', $name,
            '--expected-head-sha', $expectedHeadSha,
            '--destination', $destination,
            '--api-url', $apiUrl,
        ];
        if ($expectedEvent !== null) {
            $arguments = [...$arguments, '--expected-event', $expectedEvent];
        }
        if ($expectedWorkflowPath !== null) {
            $arguments = [...$arguments, '--expected-workflow-path', $expectedWorkflowPath];
        }

        return $this->run('restore_release_artifact.py', $arguments, $environment);
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
        return $this->run('release_notes_from_pull_requests.py', [], [
            'RELEASE_NOTES_REPOSITORY' => $repository,
            'RELEASE_NOTES_BRANCH' => $branch,
            'RELEASE_NOTES_WORKING_DIRECTORY' => $workingDirectory,
            'RELEASE_NOTES_FROM_REF' => $fromRef,
            'RELEASE_NOTES_TO_REF' => $toRef,
            'RELEASE_NOTES_FALLBACK_LIMIT' => (string) $fallbackLimit,
            'GITHUB_API_URL' => $apiUrl,
            'GITHUB_SERVER_URL' => $serverUrl,
        ] + $environment);
    }

    /** @param list<string> $arguments @param array<string, string> $environment */
    private function run(string $script, array $arguments, array $environment): Process
    {
        $process = new Process(['python3', $this->fixtureDirectory . '/' . $script, ...$arguments], null, $environment);
        $process->run();

        return $process;
    }
}
