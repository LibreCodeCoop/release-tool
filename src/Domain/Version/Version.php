<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Version;

use InvalidArgumentException;

final readonly class Version implements \Stringable
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?ReleaseChannel $channel = null,
        public ?int $prereleaseNumber = null,
        public bool $development = false,
        public ?int $developmentNumber = null,
    ) {
        if ($major < 0 || $minor < 0 || $patch < 0) {
            throw new InvalidArgumentException('Version numbers cannot be negative.');
        }
        if (($channel === null) !== ($prereleaseNumber === null)) {
            throw new InvalidArgumentException('Prerelease channel and number must be provided together.');
        }
        if ($prereleaseNumber !== null && $prereleaseNumber < 1) {
            throw new InvalidArgumentException('Prerelease number must be positive.');
        }
        if ($development && $channel !== null) {
            throw new InvalidArgumentException('Development and prerelease suffixes cannot be combined.');
        }
        if (!$development && $developmentNumber !== null) {
            throw new InvalidArgumentException('Development number requires a development version.');
        }
        if ($developmentNumber !== null && $developmentNumber < 1) {
            throw new InvalidArgumentException('Development number must be positive.');
        }
    }

    public static function parse(string $value): self
    {
        $value = ltrim(trim($value), 'v');
        if (!preg_match(
            '/^(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)(?:(?<dev>-dev)(?:\.(?<dev_number>\d+))?|-(?<channel>alpha|beta|rc)\.(?<number>\d+))?$/',
            $value,
            $match,
        )) {
            throw new InvalidArgumentException(sprintf('Invalid version: %s', $value));
        }

        $channel = ($match['channel'] ?? '') !== '' ? ReleaseChannel::from($match['channel']) : null;
        $number = ($match['number'] ?? '') !== '' ? (int) $match['number'] : null;
        $development = ($match['dev'] ?? '') !== '';
        $developmentNumber = ($match['dev_number'] ?? '') !== '' ? (int) $match['dev_number'] : null;

        return new self(
            (int) $match['major'],
            (int) $match['minor'],
            (int) $match['patch'],
            $channel,
            $number,
            $development,
            $developmentNumber,
        );
    }

    public function base(): self
    {
        return new self($this->major, $this->minor, $this->patch);
    }

    public function compare(self $other): int
    {
        foreach (['major', 'minor', 'patch'] as $part) {
            $comparison = $this->{$part} <=> $other->{$part};
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $thisRank = $this->suffixRank();
        $otherRank = $other->suffixRank();
        if ($thisRank !== $otherRank) {
            return $thisRank <=> $otherRank;
        }

        if ($this->development && $other->development) {
            return ($this->developmentNumber ?? 0) <=> ($other->developmentNumber ?? 0);
        }

        return ($this->prereleaseNumber ?? 0) <=> ($other->prereleaseNumber ?? 0);
    }

    public function __toString(): string
    {
        $base = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
        if ($this->development) {
            return $base . '-dev' . ($this->developmentNumber !== null ? '.' . $this->developmentNumber : '');
        }
        if ($this->channel !== null) {
            return sprintf('%s-%s.%d', $base, $this->channel->value, $this->prereleaseNumber);
        }

        return $base;
    }

    private function suffixRank(): int
    {
        if ($this->development) {
            return 0;
        }
        if ($this->channel !== null) {
            return $this->channel->rank();
        }

        return ReleaseChannel::Final->rank();
    }
}
