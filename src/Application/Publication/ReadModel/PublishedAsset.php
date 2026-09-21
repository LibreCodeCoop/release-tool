<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication\ReadModel;

final readonly class PublishedAsset
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?string $sha256,
    ) {
    }
}
