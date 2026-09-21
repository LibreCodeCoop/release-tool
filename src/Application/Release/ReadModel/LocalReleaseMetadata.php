<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\ReadModel;

use JsonSerializable;

final readonly class LocalReleaseMetadata implements JsonSerializable
{
    public function __construct(
        public string $version,
        public int $major,
        public bool $development,
        public string $changelogPath,
        public bool $releaseSectionRequired,
        public bool $releaseSectionPresent,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 1,
            'version' => $this->version,
            'major' => $this->major,
            'development' => $this->development,
            'changelog_path' => $this->changelogPath,
            'release_section_required' => $this->releaseSectionRequired,
            'release_section_present' => $this->releaseSectionPresent,
        ];
    }
}
