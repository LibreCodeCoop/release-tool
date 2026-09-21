<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\Configuration;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Application\Configuration\ConsumerConfigContextValidator;
use LibreCode\ReleaseTool\Domain\Configuration\ConsumerConfig;
use LibreCode\ReleaseTool\Infrastructure\Git\LocalGitRepository;

final readonly class LocalConsumerConfigContextValidator implements ConsumerConfigContextValidator
{
    public function validate(ConsumerConfig $config, string $root): void
    {
        $root = realpath($root) ?: $root;
        if (!is_dir($root)) {
            throw new InvalidArgumentException(sprintf('Repository root does not exist: %s', $root));
        }

        foreach (array_merge([$config->versionSource], $config->versionMirrors) as $path) {
            if (!is_file($root . DIRECTORY_SEPARATOR . $path)) {
                throw new InvalidArgumentException(sprintf('Configured release file does not exist: %s', $path));
            }
        }

        $gitRepository = (new LocalGitRepository($root))->repositoryIdentity();
        $environmentRepository = getenv('GITHUB_REPOSITORY') ?: null;
        $expected = $config->repository ?? $gitRepository ?? $environmentRepository;

        if ($expected === null) {
            throw new InvalidArgumentException('Repository identity is unavailable; configure repository or use a GitHub remote.');
        }

        foreach ([$gitRepository, $environmentRepository] as $observed) {
            if ($observed !== null && strcasecmp($expected, $observed) !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'Repository identity mismatch: expected %s, observed %s.',
                    $expected,
                    $observed,
                ));
            }
        }
    }
}
