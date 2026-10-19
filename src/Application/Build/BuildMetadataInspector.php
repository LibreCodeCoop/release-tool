<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Build;

use DomainException;
use JsonException;
use LibreCode\ReleaseTool\Application\Build\Port\BuildFileReader;
use SimpleXMLElement;

final readonly class BuildMetadataInspector
{
    public function __construct(private BuildFileReader $reader)
    {
    }

    public function inspect(string $root): BuildMetadata
    {
        $xmlContent = $this->reader->read($root, 'appinfo/info.xml');
        if ($xmlContent === null) {
            throw new DomainException('Missing appinfo/info.xml in application checkout.');
        }

        $xml = @simplexml_load_string($xmlContent, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new DomainException('Invalid appinfo/info.xml XML.');
        }

        $version = trim((string) ($xml->version ?? ''));
        $nextcloud = $xml->dependencies->nextcloud ?? null;
        $minimum = $nextcloud === null ? '' : trim((string) ($nextcloud['min-version'] ?? ''));
        if ($version === '' || preg_match('/[\x00-\x1f\x7f]/', $version) === 1) {
            throw new DomainException('appinfo/info.xml is missing a safe version.');
        }
        if (preg_match('/^[1-9][0-9]*$/', $minimum) !== 1) {
            throw new DomainException('appinfo/info.xml is missing a numeric Nextcloud min-version.');
        }

        $json = $this->reader->read($root, 'package.json');
        if ($json === null) {
            return new BuildMetadata($version, $minimum, '', '', false);
        }

        try {
            $package = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Invalid package.json JSON.', 0, $exception);
        }
        if (!$package instanceof \stdClass) {
            throw new DomainException('package.json must be an object.');
        }
        $engines = $package->engines ?? new \stdClass();
        if (!$engines instanceof \stdClass) {
            throw new DomainException('package.json engines must be an object.');
        }

        return new BuildMetadata(
            $version,
            $minimum,
            $this->engine($engines, 'node', '^24'),
            $this->engine($engines, 'npm', '^11.3'),
            true,
        );
    }

    /** @param \stdClass $engines */
    private function engine(\stdClass $engines, string $name, string $fallback): string
    {
        $value = $engines->{$name} ?? '';
        if (!is_string($value)) {
            throw new DomainException(sprintf('package.json engines.%s must be a string.', $name));
        }
        $value = trim($value);
        if (strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new DomainException(sprintf('package.json engines.%s contains invalid control characters.', $name));
        }

        return $value !== '' ? $value : $fallback;
    }
}
