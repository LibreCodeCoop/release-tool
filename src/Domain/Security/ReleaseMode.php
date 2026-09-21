<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Security;

enum ReleaseMode: string
{
    case Normal = 'normal';
    case Security = 'security';
}
