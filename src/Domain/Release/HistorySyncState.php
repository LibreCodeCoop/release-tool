<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Release;

enum HistorySyncState: string
{
    case NotRequired = 'not_required';
    case Planned = 'planned';
    case PullRequestOpen = 'pull_request_open';
    case AlreadySynchronized = 'already_synchronized';
    case DeferredSecurity = 'deferred_security';
}
