<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Console\Command;

use LibreCode\ReleaseTool\Application\Release\ReleasePlanReporter;
use LibreCode\ReleaseTool\Domain\Release\ReleasePlan;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ReleasePlanOutputPublisher
{
    public function __construct(
        private ReleasePlanReporter $reporter = new ReleasePlanReporter(),
    ) {
    }

    public function publish(
        ReleasePlan $plan,
        ReleasePlanOutputOptions $options,
        OutputInterface $output,
    ): void {
        $json = (string) json_encode(
            $plan,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        $this->writeFile($options->outputFile, $json . "\n");
        $this->writeGithubOutput($options, $plan);
        $this->writeSummary($options, $plan);
        $this->writeAnnotations($options, $plan, $output);
        $this->writeConsole($options, $plan, $json, $output);
    }

    private function writeGithubOutput(ReleasePlanOutputOptions $options, ReleasePlan $plan): void
    {
        if ($options->githubOutput === null) {
            return;
        }

        file_put_contents(
            $options->githubOutput,
            sprintf(
                "ready=%s\nplan-path=%s\n",
                $plan->ready ? 'true' : 'false',
                $options->outputFile ?? '',
            ),
            FILE_APPEND | LOCK_EX,
        );
    }

    private function writeSummary(ReleasePlanOutputOptions $options, ReleasePlan $plan): void
    {
        if ($options->githubStepSummary === null) {
            return;
        }

        file_put_contents(
            $options->githubStepSummary,
            $this->reporter->summary($plan, $options->toolVersion),
            FILE_APPEND | LOCK_EX,
        );
    }

    private function writeAnnotations(
        ReleasePlanOutputOptions $options,
        ReleasePlan $plan,
        OutputInterface $output,
    ): void {
        if (!$options->githubAnnotations) {
            return;
        }

        foreach ($this->reporter->githubAnnotations($plan) as $annotation) {
            $output->writeln($annotation);
        }
    }

    private function writeConsole(
        ReleasePlanOutputOptions $options,
        ReleasePlan $plan,
        string $json,
        OutputInterface $output,
    ): void {
        if ($options->json) {
            $output->writeln($json);
            return;
        }

        if ($options->outputFile !== null) {
            return;
        }

        $output->writeln($this->reporter->human($plan));
    }

    private function writeFile(?string $path, string $content): void
    {
        if ($path === null) {
            return;
        }

        file_put_contents($path, $content, LOCK_EX);
    }
}
