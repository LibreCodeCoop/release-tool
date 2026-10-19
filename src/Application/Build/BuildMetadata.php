<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Build;

final readonly class BuildMetadata
{
    public function __construct(
        public string $version,
        public string $nextcloudMinVersion,
        public string $nodeVersion,
        public string $npmVersion,
        public bool $hasPackageJson,
    ) {
    }

    /** @return array{schema:int,version:string,nextcloud_min_version:string,node_version:string,npm_version:string,has_package_json:bool} */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'version' => $this->version,
            'nextcloud_min_version' => $this->nextcloudMinVersion,
            'node_version' => $this->nodeVersion,
            'npm_version' => $this->npmVersion,
            'has_package_json' => $this->hasPackageJson,
        ];
    }
}
