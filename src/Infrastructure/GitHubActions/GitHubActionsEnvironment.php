<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Infrastructure\GitHubActions;

use LibreCode\ReleaseTool\Application\Console\Port\ActionEnvironment;
use RuntimeException;

final readonly class GitHubActionsEnvironment implements ActionEnvironment
{
    public function __construct(
        private ?string $outputPath,
        private ?string $summaryPath,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            ($value = getenv('GITHUB_OUTPUT')) !== false && $value !== '' ? $value : null,
            ($value = getenv('GITHUB_STEP_SUMMARY')) !== false && $value !== '' ? $value : null,
        );
    }

    public function output(string $name, string $value): void
    {
        if ($this->outputPath === null) {
            return;
        }

        $this->append($this->outputPath, $name . '=' . $value . PHP_EOL);
    }

    public function summary(string $markdown): void
    {
        if ($this->summaryPath === null) {
            return;
        }

        $this->append($this->summaryPath, $markdown);
    }

    private function append(string $path, string $content): void
    {
        if (file_put_contents($path, $content, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Could not write GitHub Actions file: %s', $path));
        }
    }
}
