<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Release\ReleaseDraft;

final class ReleaseDraftCodec
{
    public function encode(ReleaseDraft $draft): string
    {
        return (string) json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function decode(string $json): ReleaseDraft
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported ReleaseDraft schema.');
        }
        $release = $this->object($data, 'github_release');
        return new ReleaseDraft(
            $this->string($data, 'id'),
            $this->string($data, 'prepared_release_id'),
            $this->string($data, 'milestone_transition_id'),
            $this->int($release, 'id'),
            $this->string($release, 'url'),
            $this->string($data, 'tag_name'),
            $this->string($data, 'target_sha'),
            $this->bool($data, 'prerelease'),
            $this->string($data, 'body_sha256'),
            $this->bool($data, 'draft'),
            $this->bool($data, 'ready'),
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
