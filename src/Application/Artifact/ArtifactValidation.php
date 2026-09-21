<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Artifact;

use JsonSerializable;

final readonly class ArtifactValidation implements JsonSerializable
{
    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     * @param array<string, mixed> $changelog
     * @param array<string, mixed> $structure
     */
    public function __construct(
        public string $id,
        public string $artifactPath,
        public string $artifactName,
        public string $sha256,
        public string $expectedAppId,
        public ?string $actualAppId,
        public string $expectedVersion,
        public ?string $actualVersion,
        public array $changelog,
        public array $structure,
        public array $warnings,
        public array $errors,
        public bool $valid,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'artifact_path' => $this->artifactPath,
            'artifact_name' => $this->artifactName,
            'sha256' => $this->sha256,
            'expected_app_id' => $this->expectedAppId,
            'actual_app_id' => $this->actualAppId,
            'expected_version' => $this->expectedVersion,
            'actual_version' => $this->actualVersion,
            'changelog' => $this->changelog,
            'structure' => $this->structure,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'valid' => $this->valid,
        ];
    }
}
