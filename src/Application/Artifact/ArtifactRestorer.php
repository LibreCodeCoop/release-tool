<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

use LibreCode\ReleaseTool\Application\Artifact\Port\ActionsArtifactRepository;
use LibreCode\ReleaseTool\Application\Artifact\Port\ArchiveExtractor;
use RuntimeException;

final readonly class ArtifactRestorer
{
    public function __construct(
        private ActionsArtifactRepository $artifacts,
        private ArchiveExtractor $extractor,
    ) {
    }

    public function restore(
        string $repository,
        string $name,
        string $destination,
        ?string $expectedHeadSha = null,
        ?string $expectedEvent = null,
        ?string $expectedWorkflowPath = null,
    ): ArtifactRestore {
        $candidates = array_values(array_filter(
            $this->artifacts->artifacts($repository, $name),
            static fn (ActionsArtifact $artifact): bool => !$artifact->expired
                && $artifact->name === $name
                && ($expectedHeadSha === null || $artifact->headSha === $expectedHeadSha),
        ));

        if ($candidates === []) {
            $suffix = $expectedHeadSha === null ? '' : ' for head ' . $expectedHeadSha;
            throw new RuntimeException(sprintf("Actions artifact '%s'%s was not found", $name, $suffix));
        }

        usort($candidates, static function (ActionsArtifact $left, ActionsArtifact $right): int {
            $created = strcmp($right->createdAt, $left->createdAt);
            return $created !== 0 ? $created : $right->id <=> $left->id;
        });
        $artifact = $candidates[0];

        if ($expectedEvent !== null || $expectedWorkflowPath !== null) {
            if ($artifact->workflowRunId === null) {
                throw new RuntimeException('GitHub returned an artifact without workflow run identity');
            }
            $run = $this->artifacts->workflowRun($repository, $artifact->workflowRunId);
            if ($expectedEvent !== null && $run->event !== $expectedEvent) {
                throw new RuntimeException(sprintf(
                    "artifact workflow event '%s' does not match '%s'",
                    $run->event,
                    $expectedEvent,
                ));
            }
            if ($expectedWorkflowPath !== null && $run->path !== $expectedWorkflowPath) {
                throw new RuntimeException(sprintf(
                    "artifact workflow path '%s' does not match '%s'",
                    $run->path,
                    $expectedWorkflowPath,
                ));
            }
        }

        $temporary = sys_get_temp_dir() . '/release-artifact-' . bin2hex(random_bytes(12)) . '.zip';

        try {
            $this->artifacts->download($artifact->archiveDownloadUrl, $temporary);
            $this->extractor->extract($temporary, $destination);
        } finally {
            @unlink($temporary);
        }

        return new ArtifactRestore(
            $artifact->id,
            $artifact->workflowRunId,
            $artifact->name,
            $artifact->createdAt,
        );
    }
}
