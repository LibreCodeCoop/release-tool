<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Release\FileChange;
use LibreCode\ReleaseTool\Domain\Release\ReleasePreparation;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final class ReleasePreparationCodec
{
    public function encode(ReleasePreparation $preparation): string
    {
        return (string) json_encode(
            $preparation,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    public function decode(string $json): ReleasePreparation
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported ReleasePreparation schema.');
        }

        $changes = [];
        foreach ($this->list($data, 'file_changes') as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid ReleasePreparation file change.');
            }
            $changes[] = new FileChange(
                $this->string($item, 'path'),
                $this->string($item, 'before_sha256'),
                $this->string($item, 'after_sha256'),
                null,
            );
        }

        $changelog = $this->object($data, 'changelog');
        $pullRequest = $data['pull_request'] ?? null;
        if ($pullRequest !== null && array_is_list($pullRequest)) {
            throw new InvalidArgumentException('ReleasePreparation pull_request must be an object or null.');
        }

        return new ReleasePreparation(
            $this->string($data, 'id'),
            $this->string($data, 'release_plan_id'),
            $this->string($data, 'repository'),
            $this->string($data, 'target_branch'),
            $this->string($data, 'planning_base_sha'),
            $this->string($data, 'version'),
            ReleaseChannel::from($this->string($data, 'channel')),
            ReleaseMode::from($this->string($data, 'mode')),
            $changes,
            $this->string($changelog, 'section'),
            $this->string($changelog, 'sha256'),
            $this->string($data, 'generated_branch'),
            $this->string($data, 'pr_marker'),
            $pullRequest === null ? null : $this->int($pullRequest, 'number'),
            $pullRequest === null ? null : $this->string($pullRequest, 'url'),
        );
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function int(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function object(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || array_is_list($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data @return list<mixed> */
    private function list(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be a list.', $key));
        }
        return $data[$key];
    }
}
