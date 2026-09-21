<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication\ReadModel;

final readonly class PublishedRelease
{
    /** @param list<PublishedAsset> $assets */
    public function __construct(
        public int $id,
        public string $url,
        public string $tagName,
        public string $targetSha,
        public bool $draft,
        public bool $prerelease,
        public ?string $publishedAt,
        public array $assets,
    ) {
    }
}
