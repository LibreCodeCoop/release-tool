<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

final readonly class ReleaseDraftInfo
{
    public function __construct(
        public int $id,
        public string $url,
        public string $tagName,
        public string $targetCommitish,
        public string $body,
        public bool $draft,
        public bool $prerelease,
    ) {
    }
}
