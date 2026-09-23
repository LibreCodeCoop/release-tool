<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Release\HistorySynchronization;
use LibreCode\ReleaseTool\Domain\Release\HistorySyncState;
use LibreCode\ReleaseTool\Domain\Release\PreparedRelease;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;
use LibreCode\ReleaseTool\Domain\Version\ReleaseChannel;

final class PreparedReleaseCodec
{
    public function encode(PreparedRelease $prepared): string
    {
        return (string) json_encode($prepared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function decode(string $json): PreparedRelease
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported PreparedRelease schema.');
        }
        $pr = $this->object($data, 'release_pull_request');
        $changelog = $this->object($data, 'changelog');
        $history = $this->object($data, 'history_synchronization');
        $historySynchronization = $this->historySynchronization($history);
        $historySynchronizations = [$historySynchronization];
        if (isset($data['history_synchronizations'])) {
            if (!is_array($data['history_synchronizations']) || !array_is_list($data['history_synchronizations'])) {
                throw new InvalidArgumentException('history_synchronizations must be a list.');
            }
            $historySynchronizations = [];
            foreach ($data['history_synchronizations'] as $entry) {
                if (!is_array($entry) || array_is_list($entry)) {
                    throw new InvalidArgumentException('history_synchronizations entries must be objects.');
                }
                $historySynchronizations[] = $this->historySynchronization($entry);
            }
            if ($historySynchronizations === []) {
                throw new InvalidArgumentException('history_synchronizations must not be empty.');
            }
        }
        $files = $this->object($data, 'release_files');
        foreach ($files as $path => $digest) {
            if (!is_string($path) || !is_string($digest)) {
                throw new InvalidArgumentException('PreparedRelease release_files must map paths to digests.');
            }
        }

        return new PreparedRelease(
            $this->string($data, 'id'),
            $this->string($data, 'release_plan_id'),
            $this->string($data, 'release_preparation_id'),
            $this->string($data, 'repository'),
            $this->string($data, 'branch'),
            $this->int($pr, 'number'),
            $this->string($pr, 'url'),
            $this->string($data, 'final_sha'),
            $this->string($data, 'version'),
            $this->string($data, 'tag_name'),
            ReleaseChannel::from($this->string($data, 'channel')),
            ReleaseMode::from($this->string($data, 'mode')),
            $this->string($changelog, 'target'),
            $this->string($changelog, 'section'),
            $this->string($changelog, 'sha256'),
            $files,
            $historySynchronization,
            $historySynchronizations,
        );
    }


    /** @param array<string, mixed> $history */
    private function historySynchronization(array $history): HistorySynchronization
    {
        $historyPr = $history['pull_request'] ?? null;
        if ($historyPr !== null && (!is_array($historyPr) || array_is_list($historyPr))) {
            throw new InvalidArgumentException('history synchronization pull_request must be an object or null.');
        }

        return new HistorySynchronization(
            HistorySyncState::from($this->string($history, 'state')),
            $this->string($history, 'target_branch'),
            $this->string($history, 'target_path'),
            isset($history['generated_branch']) ? $this->string($history, 'generated_branch') : null,
            is_array($historyPr) ? $this->int($historyPr, 'number') : null,
            is_array($historyPr) ? $this->string($historyPr, 'url') : null,
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
}
