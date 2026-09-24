<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use DomainException;
use LibreCode\ReleaseTool\Application\Release\Port\ReleasePreflightRepository;
use SimpleXMLElement;

final readonly class ReleasePreflight
{
    public function __construct(private ReleasePreflightRepository $github)
    {
    }

    /**
     * @param list<string> $blockerQueries
     * @return array{version:string,stable_branch:string,repository:string|null,ready:bool,checks:list<array{name:string,ok:bool,message:string}>}
     */
    public function check(
        string $version,
        string $stableBranch,
        string $currentRef,
        ?string $repository,
        string $appinfoPath,
        string $changelogPath,
        ?string $milestone,
        array $blockerQueries,
    ): array {
        $checks = [
            $this->result(
                'version',
                preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) === 1,
                sprintf('release version is %s', $version),
                'version must use MAJOR.MINOR.PATCH',
            ),
            $this->result(
                'branch',
                $currentRef === $stableBranch,
                sprintf('current ref matches stable branch %s', $stableBranch),
                sprintf("current ref '%s' does not match stable branch '%s'", $currentRef, $stableBranch),
            ),
            $this->checkAppinfo($appinfoPath, $version),
            $this->checkChangelog($changelogPath, $version),
        ];

        if ($milestone !== null && $milestone !== '') {
            $repository = $this->requiredRepository($repository);
            $value = $this->github->milestone($repository, $milestone);
            $ok = $value !== null && $value['state'] === 'closed' && $value['open_issues'] === 0;
            $message = $ok
                ? sprintf("milestone '%s' is closed with no open issues", $milestone)
                : ($value === null
                    ? sprintf("milestone '%s' does not exist", $milestone)
                    : sprintf(
                        "milestone '%s' has state='%s' and open_issues=%d",
                        $milestone,
                        $value['state'],
                        $value['open_issues'],
                    ));
            $checks[] = ['name' => 'milestone', 'ok' => $ok, 'message' => $message];
        }

        foreach ($blockerQueries as $query) {
            $repository = $this->requiredRepository($repository);
            $count = $this->github->openItemCount($repository, $query);
            $checks[] = $this->result(
                'blocker:' . $query,
                $count === 0,
                sprintf("no open items match '%s'", $query),
                sprintf("%d open item(s) match '%s'", $count, $query),
            );
        }

        return [
            'version' => $version,
            'stable_branch' => $stableBranch,
            'repository' => $repository,
            'ready' => array_all($checks, static fn (array $check): bool => $check['ok']),
            'checks' => $checks,
        ];
    }

    /** @return array{name:string,ok:bool,message:string} */
    private function checkAppinfo(string $path, string $version): array
    {
        if (!is_file($path)) {
            return $this->result('appinfo', false, '', sprintf('%s does not exist', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->result('appinfo', false, '', sprintf('cannot read %s', $path));
        }

        try {
            $xml = new SimpleXMLElement($content);
        } catch (\Throwable $exception) {
            return $this->result('appinfo', false, '', sprintf('cannot parse %s: %s', $path, $exception->getMessage()));
        }

        $declared = trim((string) $xml->version);
        return $this->result(
            'appinfo',
            $declared === $version,
            sprintf('%s declares version %s', $path, $version),
            sprintf("%s declares version '%s', expected '%s'", $path, $declared, $version),
        );
    }

    /** @return array{name:string,ok:bool,message:string} */
    private function checkChangelog(string $path, string $version): array
    {
        if (!is_file($path)) {
            return $this->result('changelog', false, '', sprintf('%s does not exist', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->result('changelog', false, '', sprintf('cannot read %s', $path));
        }

        $pattern = '/^##\s+' . preg_quote($version, '/') . '(?:\s+-\s+.+)?\s*$/m';
        return $this->result(
            'changelog',
            preg_match($pattern, $content) === 1,
            sprintf('%s contains a section for %s', $path, $version),
            sprintf('%s does not contain a level-2 section for %s', $path, $version),
        );
    }

    /** @return array{name:string,ok:bool,message:string} */
    private function result(string $name, bool $ok, string $success, string $failure): array
    {
        return ['name' => $name, 'ok' => $ok, 'message' => $ok ? $success : $failure];
    }

    private function requiredRepository(?string $repository): string
    {
        if ($repository === null || substr_count($repository, '/') !== 1) {
            throw new DomainException('repository must use OWNER/REPO format for GitHub checks');
        }
        return $repository;
    }
}
