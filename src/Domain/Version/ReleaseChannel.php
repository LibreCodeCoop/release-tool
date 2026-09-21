<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Version;

enum ReleaseChannel: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
    case Rc = 'rc';
    case Final = 'final';

    public function isPrerelease(): bool
    {
        return $this !== self::Final;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Alpha => 1,
            self::Beta => 2,
            self::Rc => 3,
            self::Final => 4,
        };
    }
}
