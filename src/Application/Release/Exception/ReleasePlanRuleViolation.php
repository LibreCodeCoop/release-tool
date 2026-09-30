<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Application\Release\Exception;

final class ReleasePlanRuleViolation extends \DomainException implements ReleasePlanFailure
{
}
