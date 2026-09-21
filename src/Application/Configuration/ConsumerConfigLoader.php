<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Configuration;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConsumerConfigLoader
{
    private const array ROOT_KEYS = ['schema', 'repository', 'app', 'branches', 'version', 'history', 'changelog', 'milestones', 'authorization', 'package', 'publication'];
    private const array PERMISSIONS = ['read', 'triage', 'write', 'maintain', 'admin'];
    private const array TEMPLATE_VALUES = ['nextcloud', 'major', 'version'];

    public function load(string $path): ConsumerConfig
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Configuration file not found: %s', $path));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('Invalid YAML: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException('Configuration root must be a mapping.');
        }

        $this->assertKnownKeys($data, self::ROOT_KEYS, 'root');
        $schema = $this->requiredInt($data, 'schema', 'root');
        if ($schema !== 1) {
            throw new InvalidArgumentException(sprintf('Unsupported configuration schema: %d', $schema));
        }

        $app = $this->mapping($data, 'app', ['id', 'main_branch']);
        $branches = $this->mapping($data, 'branches', ['stable_pattern']);
        $version = $this->mapping($data, 'version', ['source', 'mirrors', 'tag_prefix']);
        $history = $this->mapping($data, 'history', ['previous_release', 'initial_ref']);
        $changelog = $this->mapping($data, 'changelog', ['strategy', 'path', 'package_root']);
        $milestones = $this->mapping($data, 'milestones', ['patch', 'rc']);
        $authorization = $this->mapping($data, 'authorization', ['prepare_min_permission', 'merge_min_permission']);
        $package = $this->mapping($data, 'package', ['command', 'required_paths', 'forbidden_paths']);
        $publication = isset($data['publication'])
            ? $this->mapping($data, 'publication', ['publisher_workflow', 'asset_name', 'appstore_api'])
            : null;

        $stablePattern = $this->requiredString($branches, 'stable_pattern', 'branches');
        set_error_handler(static fn (): bool => true);
        $patternResult = preg_match('~' . str_replace('~', '\\~', $stablePattern) . '~', '');
        restore_error_handler();
        if ($patternResult === false) {
            throw new InvalidArgumentException('branches.stable_pattern must be a valid PCRE expression.');
        }

        $mirrors = $this->stringList($version['mirrors'] ?? [], 'version.mirrors');

        $strategy = $this->requiredString($history, 'previous_release', 'history');
        if ($strategy !== 'reachable-tag') {
            throw new InvalidArgumentException('history.previous_release must be reachable-tag for schema v1.');
        }

        $changelogStrategy = $this->requiredString($changelog, 'strategy', 'changelog');
        if ($changelogStrategy !== 'per-major') {
            throw new InvalidArgumentException('changelog.strategy must be per-major for schema v1.');
        }
        $changelogPath = $this->requiredString($changelog, 'path', 'changelog');
        $this->assertTemplate($changelogPath, ['major'], 'changelog.path');
        if (!str_contains($changelogPath, '{major}')) {
            throw new InvalidArgumentException('changelog.path must contain {major}.');
        }

        $patchMilestone = $this->requiredString($milestones, 'patch', 'milestones');
        $rcMilestone = $this->requiredString($milestones, 'rc', 'milestones');
        $this->assertTemplate($patchMilestone, self::TEMPLATE_VALUES, 'milestones.patch');
        $this->assertTemplate($rcMilestone, self::TEMPLATE_VALUES, 'milestones.rc');

        $preparePermission = $this->requiredString($authorization, 'prepare_min_permission', 'authorization');
        $mergePermission = $this->requiredString($authorization, 'merge_min_permission', 'authorization');
        foreach ([$preparePermission, $mergePermission] as $permission) {
            if (!in_array($permission, self::PERMISSIONS, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported repository permission: %s', $permission));
            }
        }

        $command = $package['command'] ?? null;
        if (!is_array($command) || !array_is_list($command) || $command === []) {
            throw new InvalidArgumentException('package.command must be a non-empty argument list.');
        }
        foreach ($command as $index => $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('package.command arguments must be non-empty strings.');
            }
            if ($index === 0 && preg_match('#^[A-Za-z0-9._/+-]+$#', $argument) !== 1) {
                throw new InvalidArgumentException('package.command executable must not contain shell syntax or whitespace.');
            }
        }

        $requiredPaths = $this->packagePaths($package['required_paths'] ?? [], 'package.required_paths');
        $forbiddenPaths = $this->packagePaths($package['forbidden_paths'] ?? [], 'package.forbidden_paths');

        $publisherWorkflow = $publication !== null
            ? $this->requiredString($publication, 'publisher_workflow', 'publication')
            : null;
        $assetName = $publication !== null
            ? $this->requiredString($publication, 'asset_name', 'publication')
            : null;
        $appStoreApi = $publication !== null
            ? $this->requiredString($publication, 'appstore_api', 'publication')
            : null;
        if ($assetName !== null) {
            $this->assertTemplate($assetName, ['app', 'version', 'tag'], 'publication.asset_name');
        }
        if ($appStoreApi !== null && filter_var($appStoreApi, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('publication.appstore_api must be a valid URL.');
        }

        $repository = $data['repository'] ?? null;
        if ($repository !== null && (!is_string($repository) || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) !== 1)) {
            throw new InvalidArgumentException('repository must be in owner/name form.');
        }

        return new ConsumerConfig(
            $schema,
            $this->requiredString($app, 'id', 'app'),
            $this->requiredString($app, 'main_branch', 'app'),
            $repository,
            $stablePattern,
            $this->requiredString($version, 'source', 'version'),
            $mirrors,
            $this->requiredString($version, 'tag_prefix', 'version'),
            $strategy,
            isset($history['initial_ref']) ? $this->stringValue($history['initial_ref'], 'history.initial_ref') : null,
            $changelogStrategy,
            $changelogPath,
            $this->requiredString($changelog, 'package_root', 'changelog'),
            $patchMilestone,
            $rcMilestone,
            $preparePermission,
            $mergePermission,
            $command,
            $requiredPaths,
            $forbiddenPaths,
            $publisherWorkflow,
            $assetName,
            $appStoreApi,
        );
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function assertKnownKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unknown configuration key at %s: %s', $path, (string) $key));
            }
        }
    }

    /** @param array<string, mixed> $data @param list<string> $allowed @return array<string, mixed> */
    private function mapping(array $data, string $key, array $allowed): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a mapping.', $key));
        }
        $this->assertKnownKeys($value, $allowed, $key);

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $path): string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException(sprintf('Missing required key: %s.%s', $path, $key));
        }

        return $this->stringValue($data[$key], $path . '.' . $key);
    }

    private function stringValue(mixed $value, string $path): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $path));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredInt(array $data, string $key, string $path): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s.%s must be an integer.', $path, $key));
        }

        return $data[$key];
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', $path));
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException(sprintf('%s must contain non-empty paths.', $path));
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @return list<string> */
    private function packagePaths(mixed $value, string $path): array
    {
        $paths = $this->stringList($value, $path);
        foreach ($paths as $item) {
            if (
                str_starts_with($item, '/')
                || str_contains($item, '\\')
                || str_contains($item, "\0")
                || in_array('..', explode('/', $item), true)
                || in_array('.', explode('/', $item), true)
            ) {
                throw new InvalidArgumentException(sprintf('%s must contain safe relative paths.', $path));
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param list<string> $allowed */
    private function assertTemplate(string $template, array $allowed, string $path): void
    {
        preg_match_all('/\{(?<name>[A-Za-z0-9_]+)\}/', $template, $matches);
        foreach ($matches['name'] as $name) {
            if (!in_array($name, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported placeholder {%s} in %s.', $name, $path));
            }
        }
    }
}
