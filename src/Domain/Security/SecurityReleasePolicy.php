<?php

declare(strict_types=1);

namespace LibreCode\ReleaseTool\Domain\Security;

final class SecurityReleasePolicy
{
    public const string NEUTRAL_SECURITY_TEXT = 'This release includes security fixes.';

    public function publicText(ReleaseMode $mode, ?string $explicitSafeText): PublicReleaseText
    {
        $safeText = $explicitSafeText !== null ? trim($explicitSafeText) : null;

        if ($safeText !== null && $safeText !== '') {
            return new PublicReleaseText($safeText, true);
        }

        if ($mode === ReleaseMode::Security) {
            return new PublicReleaseText(self::NEUTRAL_SECURITY_TEXT, false);
        }

        return new PublicReleaseText('Maintenance release.', false);
    }
}
