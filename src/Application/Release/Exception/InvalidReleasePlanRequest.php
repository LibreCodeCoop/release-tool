<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Exception;

final class InvalidReleasePlanRequest extends \InvalidArgumentException implements ReleasePlanFailure
{
}
