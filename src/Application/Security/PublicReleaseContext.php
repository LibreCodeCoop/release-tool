<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Security;

use JsonSerializable;
use LibreCode\ReleaseTool\Domain\Security\PublicReleaseText;
use LibreCode\ReleaseTool\Domain\Security\ReleaseMode;

final readonly class PublicReleaseContext implements JsonSerializable
{
    public function __construct(
        public ReleaseMode $mode,
        public PublicReleaseText $publicText,
    ) {
    }

    /** @return array{mode: string, public_text: PublicReleaseText} */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode->value,
            'public_text' => $this->publicText,
        ];
    }
}
