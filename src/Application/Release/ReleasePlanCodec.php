<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Release\BackportBlocker;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use LibreCode\ReleaseTool\Domain\Security\PublicReleaseText;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final class ReleasePlanCodec
{
    public function decode(string $json): ReleasePlan
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported ReleasePlan schema.');
        }

        $previous = $this->array($data, 'previous_release');
        $publicText = $this->array($data, 'public_release_text');

        $blockers = [];
        foreach ($this->list($data, 'backport_blockers') as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid ReleasePlan backport blocker.');
            }
            $blockers[] = new BackportBlocker(
                $this->int($item, 'number'),
                $this->string($item, 'title'),
                $this->string($item, 'url'),
            );
        }

        $activity = $this->list($data, 'activity');
        foreach ($activity as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid ReleasePlan activity item.');
            }
        }

        $warnings = $this->list($data, 'warnings');
        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                throw new InvalidArgumentException('ReleasePlan warnings must be strings.');
            }
        }

        $milestone = $data['milestone'] ?? null;
        if ($milestone !== null && !is_array($milestone)) {
            throw new InvalidArgumentException('ReleasePlan milestone must be an object or null.');
        }

        return new ReleasePlan(
            $this->string($data, 'id'),
            $this->string($data, 'repository'),
            $this->string($data, 'consumer_id'),
            $this->string($data, 'branch'),
            $this->int($data, 'nextcloud_major'),
            $this->int($data, 'app_major'),
            $this->string($data, 'planning_base_sha'),
            isset($previous['tag']) ? $this->string($previous, 'tag') : null,
            $this->string($previous, 'sha'),
            $this->string($previous, 'comparison_ref'),
            $this->string($data, 'current_version'),
            $this->string($data, 'proposed_version'),
            isset($data['explicit_version_override']) && $data['explicit_version_override'] !== null
                ? $this->string($data, 'explicit_version_override')
                : null,
            ReleaseChannel::from($this->string($data, 'channel')),
            $this->string($data, 'bump_reason'),
            $activity,
            $this->string($data, 'changelog_target'),
            $milestone,
            $blockers,
            $this->bool($data, 'ignore_open_backport'),
            $this->bool($data, 'create_follow_up_milestone'),
            ReleaseMode::from($this->string($data, 'mode')),
            new PublicReleaseText(
                $this->string($publicText, 'text'),
                $this->bool($publicText, 'explicit'),
            ),
            $warnings,
            $this->bool($data, 'ready'),
        );
    }

    public function encode(ReleasePlan $plan): string
    {
        return (string) json_encode(
            $plan,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf('ReleasePlan field %s must be a string.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function int(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new InvalidArgumentException(sprintf('ReleasePlan field %s must be an integer.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function bool(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
            throw new InvalidArgumentException(sprintf('ReleasePlan field %s must be a boolean.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function array(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || array_is_list($data[$key])) {
            throw new InvalidArgumentException(sprintf('ReleasePlan field %s must be an object.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function list(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new InvalidArgumentException(sprintf('ReleasePlan field %s must be a list.', $key));
        }

        return $data[$key];
    }
}
