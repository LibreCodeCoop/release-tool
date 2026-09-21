<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class PublicationVerification implements JsonSerializable
{
    /** @param list<string> $errors */
    public function __construct(
        public string $id,
        public string $releaseDraftId,
        public string $preparedReleaseId,
        public string $repository,
        public string $tagName,
        public string $targetSha,
        public int $releaseId,
        public string $releaseUrl,
        public bool $releasePublished,
        public string $publisherWorkflow,
        public ?int $publisherRunId,
        public ?string $publisherRunUrl,
        public ?string $publisherConclusion,
        public bool $publisherSucceeded,
        public string $artifactName,
        public ?string $artifactSha256,
        public ?string $artifactValidationId,
        public bool $artifactValid,
        public string $appStoreApi,
        public string $appId,
        public string $version,
        public bool $appStoreVisible,
        public string $verifiedAt,
        public bool $securityMode,
        public array $errors,
        public bool $success,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'id' => $this->id,
            'release_draft_id' => $this->releaseDraftId,
            'prepared_release_id' => $this->preparedReleaseId,
            'repository' => $this->repository,
            'tag_name' => $this->tagName,
            'target_sha' => $this->targetSha,
            'github_release' => [
                'id' => $this->releaseId,
                'url' => $this->releaseUrl,
                'published' => $this->releasePublished,
            ],
            'publisher' => [
                'workflow' => $this->publisherWorkflow,
                'run_id' => $this->publisherRunId,
                'run_url' => $this->publisherRunUrl,
                'conclusion' => $this->publisherConclusion,
                'success' => $this->publisherSucceeded,
            ],
            'artifact' => [
                'name' => $this->artifactName,
                'sha256' => $this->artifactSha256,
                'validation_id' => $this->artifactValidationId,
                'valid' => $this->artifactValid,
            ],
            'appstore' => [
                'api' => $this->appStoreApi,
                'app_id' => $this->appId,
                'version' => $this->version,
                'visible' => $this->appStoreVisible,
            ],
            'verified_at' => $this->verifiedAt,
            'security_mode' => $this->securityMode,
            'errors' => $this->errors,
            'success' => $this->success,
        ];
    }
}
