<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

use JsonSerializable;

final readonly class FileChange implements JsonSerializable
{
    public function __construct(
        public string $path,
        public string $beforeSha256,
        public string $afterSha256,
        public ?string $content,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'before_sha256' => $this->beforeSha256,
            'after_sha256' => $this->afterSha256,
        ];
    }
}
