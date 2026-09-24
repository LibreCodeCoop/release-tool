<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Security;

use InvalidArgumentException;

enum RepositoryPermission: string
{
    case None = 'none';
    case Read = 'read';
    case Triage = 'triage';
    case Write = 'write';
    case Maintain = 'maintain';
    case Admin = 'admin';

    public static function parse(string $value, string $context = 'repository permission'): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException(sprintf('Unsupported %s: %s', $context, $value));
    }

    public function satisfies(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Read => 1,
            self::Triage => 2,
            self::Write => 3,
            self::Maintain => 4,
            self::Admin => 5,
        };
    }
}
