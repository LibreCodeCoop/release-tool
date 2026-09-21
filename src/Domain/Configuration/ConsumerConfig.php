<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Configuration;

final readonly class ConsumerConfig
{
    /**
     * @param list<string> $versionMirrors
     * @param list<string> $packageCommand
     * @param list<string> $packageRequiredPaths
     * @param list<string> $packageForbiddenPaths
     */
    public function __construct(
        public int $schema,
        public string $appId,
        public string $mainBranch,
        public ?string $repository,
        public string $stablePattern,
        public string $versionSource,
        public array $versionMirrors,
        public string $tagPrefix,
        public string $previousReleaseStrategy,
        public ?string $initialRef,
        public string $changelogStrategy,
        public string $changelogPath,
        public string $packageRootChangelog,
        public string $patchMilestone,
        public string $rcMilestone,
        public string $prepareMinPermission,
        public string $mergeMinPermission,
        public array $packageCommand,
        public array $packageRequiredPaths = [],
        public array $packageForbiddenPaths = [],
        public ?string $publicationPublisherWorkflow = null,
        public ?string $publicationAssetName = null,
        public ?string $publicationAppStoreApi = null,
    ) {
    }
}
