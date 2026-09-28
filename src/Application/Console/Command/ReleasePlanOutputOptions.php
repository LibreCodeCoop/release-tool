<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use Symfony\Component\Console\Input\InputInterface;

final readonly class ReleasePlanOutputOptions
{
    public function __construct(
        public bool $json,
        public ?string $outputFile,
        public ?string $githubOutput,
        public ?string $githubStepSummary,
        public bool $githubAnnotations,
        public string $toolVersion,
    ) {
    }

    public static function fromInput(InputInterface $input): self
    {
        return new self(
            json: (bool) $input->getOption('json'),
            outputFile: self::optionalString($input->getOption('output-file')),
            githubOutput: self::optionalString($input->getOption('github-output')),
            githubStepSummary: self::optionalString($input->getOption('github-step-summary')),
            githubAnnotations: (bool) $input->getOption('github-annotations'),
            toolVersion: (string) $input->getOption('tool-version'),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
