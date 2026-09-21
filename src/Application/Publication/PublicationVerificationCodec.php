<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Publication;

use InvalidArgumentException;
use LibreCode\ReleaseTool\Domain\Release\PublicationVerification;

final class PublicationVerificationCodec
{
    public function encode(PublicationVerification $verification): string
    {
        return (string) json_encode(
            $verification,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    public function decode(string $json): PublicationVerification
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported PublicationVerification schema.');
        }

        $release = $this->object($data, 'github_release');
        $publisher = $this->object($data, 'publisher');
        $artifact = $this->object($data, 'artifact');
        $appstore = $this->object($data, 'appstore');
        $errors = $data['errors'] ?? null;
        if (!is_array($errors) || !array_is_list($errors)) {
            throw new InvalidArgumentException('errors must be a list.');
        }

        return new PublicationVerification(
            $this->string($data, 'id'),
            $this->string($data, 'release_draft_id'),
            $this->string($data, 'prepared_release_id'),
            $this->string($data, 'repository'),
            $this->string($data, 'tag_name'),
            $this->string($data, 'target_sha'),
            $this->int($release, 'id'),
            $this->string($release, 'url'),
            $this->bool($release, 'published'),
            $this->string($publisher, 'workflow'),
            $this->nullableInt($publisher, 'run_id'),
            $this->nullableString($publisher, 'run_url'),
            $this->nullableString($publisher, 'conclusion'),
            $this->bool($publisher, 'success'),
            $this->string($artifact, 'name'),
            $this->nullableString($artifact, 'sha256'),
            $this->nullableString($artifact, 'validation_id'),
            $this->bool($artifact, 'valid'),
            $this->string($appstore, 'api'),
            $this->string($appstore, 'app_id'),
            $this->string($appstore, 'version'),
            $this->bool($appstore, 'visible'),
            $this->string($data, 'verified_at'),
            $this->bool($data, 'security_mode'),
            array_map(static fn (mixed $error): string => (string) $error, $errors),
            $this->bool($data, 'success'),
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function object(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || array_is_list($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_string($data[$key]))) {
            throw new InvalidArgumentException(sprintf('%s must be a string or null.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function int(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function nullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_int($data[$key]))) {
            throw new InvalidArgumentException(sprintf('%s must be an integer or null.', $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function bool(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }
        return $data[$key];
    }
}
