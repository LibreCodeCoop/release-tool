<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication\ReadModel;

final readonly class PublisherRun
{
    public function __construct(
        public int $id,
        public string $url,
        public string $headSha,
        public string $event,
        public string $status,
        public ?string $conclusion,
        public string $createdAt,
    ) {
    }
}
