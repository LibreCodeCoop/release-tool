<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Release\MilestoneTransition;

final class MilestoneTransitionCodec
{
    public function encode(MilestoneTransition $transition): string
    {
        return (string) json_encode(
            $transition,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    public function decode(string $json): MilestoneTransition
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported MilestoneTransition schema.');
        }
        $released = $this->object($data, 'released_milestone');
        $moved = $this->object($data, 'moved');
        $followUp = null;
        if (array_key_exists('follow_up_milestone', $data) && $data['follow_up_milestone'] !== null) {
            $followUp = $this->object($data, 'follow_up_milestone');
        }

        return new MilestoneTransition(
            $this->string($data, 'id'),
            $this->string($data, 'prepared_release_id'),
            $this->int($released, 'number'),
            $this->string($released, 'url'),
            $this->string($released, 'final_title'),
            $followUp !== null ? $this->int($followUp, 'number') : null,
            $followUp !== null ? $this->string($followUp, 'url') : null,
            $this->int($moved, 'issues'),
            $this->int($moved, 'pull_requests'),
            $this->bool($data, 'already_applied'),
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

    /** @param array<string, mixed> $data */
    private function bool(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be a boolean.', $key));
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
}
