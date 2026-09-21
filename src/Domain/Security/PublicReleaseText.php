<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Security;

use InvalidArgumentException;
use JsonSerializable;

final readonly class PublicReleaseText implements JsonSerializable
{
    public function __construct(
        public string $text,
        public bool $explicit,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Public release text cannot be empty.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text,
            'explicit' => $this->explicit,
        ];
    }
}
